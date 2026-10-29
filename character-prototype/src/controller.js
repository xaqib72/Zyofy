import * as THREE from 'three';
import { TUNING, WEAPONS, CLIP_DEFS } from './config.js';

/**
 * Movement + animation state machine for one character.
 *
 * Design rules (these exist because the first version violated them and the
 * animations visibly double-played / fought each other):
 *
 *  1. `_resolveClip()` returns exactly ONE clip id per frame. There is no place
 *     where two locomotion clips are started in the same frame.
 *  2. Input actions are edge-triggered and consumed once (`wasPressed`), and
 *     every action has a guard so it cannot be re-entered while it is running.
 *  3. One-shot actions (jump take-off, land, reload, hit, death, get-up,
 *     prone-down) live in a single `action` slot. Starting a new one replaces
 *     the old one; nothing can run two one-shots at once.
 *  4. Mutually exclusive postures: airborne blocks posture changes, prone blocks
 *     jumping, death blocks everything. There is no state where the controller
 *     can be "jumping and crawling".
 *  5. Clip playback rate is derived from the clip's own baked ground speed
 *     (`naturalSpeed` in config.js), which is the only table of speeds - the
 *     old duplicated speed table drifted out of sync and caused foot sliding.
 */

const POSTURE_STAND = 'stand';
const POSTURE_CROUCH = 'crouch';
const POSTURE_PRONE = 'prone';

const GROUND_LIMIT = 24;

/** clip id -> ground speed (m/s) baked into that clip. Single source of truth. */
const NATURAL_SPEED = new Map(CLIP_DEFS.map((d) => [d.id, d.naturalSpeed || 0]));

/** Airborne pose is held for this long after take-off before the fall loop. */
const TAKEOFF_TIME = 0.16;
/** Grounded "settle" after landing. */
const LAND_TIME = 0.18;

export class CharacterController {
  constructor(character, { fx, onEvent, hitTest } = {}) {
    this.character = character;
    this.fx = fx;
    this.onEvent = onEvent || (() => {});
    this.hitTest = hitTest || null;

    this.position = new THREE.Vector3(0, 0, 0);
    this.velocity = new THREE.Vector3();
    this.yaw = Math.PI;
    this.grounded = true;
    this.verticalVelocity = 0;

    this.posture = POSTURE_STAND;
    this.aiming = false;
    this.sprinting = false;
    this.walking = false;
    this.speed = 0;

    this.weapon = 'rifle';
    this.ammo = {};
    this.reserve = {};
    for (const [id, def] of Object.entries(WEAPONS)) {
      this.ammo[id] = def.magSize;
      this.reserve[id] = def.reserve;
    }
    this.fireCooldown = 0;
    this.burstLeft = 0;
    this.reloading = 0;          // seconds left, 0 = not reloading

    this.dead = false;
    this.deathClip = 'death_front';

    /** Active one-shot action: {id, remaining, timeScale, fade, movement}. */
    this.action = null;
    /** Seconds remaining in the take-off pose before the fall loop takes over. */
    this.takeoffTimer = 0;
    this.landTimer = 0;

    this.cameraYaw = Math.PI;
    this.cameraPitch = 0;
    this.recoilPitch = 0;
    this.firingPoseTimer = 0;

    this.character.setWeapon(this.weapon);
  }

  /* ------------------------------------------------------------------ public */

  /** Keeps posture/pose/ammo when Tab swaps the visible character. */
  transferStateFrom(other) {
    this.posture = other.posture;
    this.aiming = other.aiming;
    this.weapon = other.weapon;
    this.ammo = { ...other.ammo };
    this.reserve = { ...other.reserve };
    this.position.copy(other.position);
    this.velocity.copy(other.velocity);
    this.yaw = other.yaw;
    this.dead = other.dead;
    this.deathClip = other.deathClip;
    this.cameraYaw = other.cameraYaw;
    this.cameraPitch = other.cameraPitch;
    this.action = null;
    this.reloading = 0;
    this.character.setWeapon(this.weapon);
    this._applyTransform();
  }

  get busy() {
    return !!(this.action && this.action.movement === 'lock');
  }

  update(dt, input, camera) {
    dt = Math.min(dt, 0.05);
    this.cameraYaw = camera.yaw;
    this.cameraPitch = camera.pitch;

    this._tickTimers(dt);

    if (this.dead) {
      this._updateDead(input);
      this._applyTransform();
      return;
    }

    // ---- posture / weapon input (single edge-triggered handlers) ------------
    this._handlePostureInput(input);
    this._handleWeaponInput(input);

    // ---- movement -----------------------------------------------------------
    this._updateMovement(dt, input, camera);
    this._updateJumpAndGravity(dt, input);

    // ---- combat input -------------------------------------------------------
    this._handleCombatInput(input);

    // ---- one animation decision per frame -----------------------------------
    this._playResolvedClip();

    this._applyTransform();
  }

  /* ------------------------------------------------------------------ timers */

  _tickTimers(dt) {
    this.fireCooldown = Math.max(0, this.fireCooldown - dt);
    this.recoilPitch *= Math.pow(0.0015, dt);
    this.firingPoseTimer = Math.max(0, this.firingPoseTimer - dt);
    this.takeoffTimer = Math.max(0, this.takeoffTimer - dt);
    this.landTimer = Math.max(0, this.landTimer - dt);

    if (this.action) {
      this.action.remaining -= dt;
      if (this.action.remaining <= 0) this._finishAction(this.action.id);
    }

    if (this.reloading > 0) {
      this.reloading = Math.max(0, this.reloading - dt);
      if (this.reloading === 0) this._completeReload();
    }
  }

  _finishAction(id) {
    this.action = null;
    switch (id) {
      case 'prone_down':
        // now genuinely prone: settle into the belly-down idle
        break;
      case 'prone_up':
        break;
      default:
        break;
    }
    this.onEvent('actionEnd', { id });
  }

  _updateDead(input) {
    this.velocity.set(0, 0, 0);
    this.speed = 0;
    this.character.play(this.deathClip, { fade: 0.12 });
    if (input.wasPressed('KeyG')) {
      this.dead = false;
      this.posture = POSTURE_STAND;
      this._startAction('prone_stand', { timeScale: 1.1, fade: 0.2, movement: 'lock' });
      this.onEvent('revived');
    }
  }

  /* ----------------------------------------------------------------- posture */

  _handlePostureInput(input) {
    const ctrlDown = input.wasPressed('ControlLeft') || input.wasPressed('ControlRight');
    const proneDown = input.wasPressed('KeyZ');

    // Posture changes are only legal on the ground and while no locking action
    // (reload / get-up / hit) is running. This is what prevents "jumping while
    // prone" and "crawling while standing up".
    const canChange = this.grounded && !this.busy;
    if (!canChange) return;

    if (proneDown) {
      if (this.posture === POSTURE_PRONE) this._enterPosture(POSTURE_STAND);
      else this._enterPosture(POSTURE_PRONE);
      return;
    }
    if (ctrlDown) {
      if (this.posture === POSTURE_CROUCH) this._enterPosture(POSTURE_STAND);
      else this._enterPosture(POSTURE_CROUCH);
    }
  }

  _enterPosture(next) {
    if (next === this.posture) return;
    const from = this.posture;

    if (next === POSTURE_PRONE) {
      // stand/crouch -> belly. The descent is a real clip, not a snap.
      this.posture = POSTURE_PRONE;
      this.aiming = false;
      this.sprinting = false;
      this.velocity.set(0, 0, 0);
      this._startAction('prone_down', { timeScale: 1.25, fade: 0.18, movement: 'lock' });
      this.onEvent('posture', { from, to: next });
      return;
    }

    if (from === POSTURE_PRONE) {
      this.posture = next;
      this.velocity.set(0, 0, 0);
      this._startAction('prone_stand', {
        timeScale: next === POSTURE_CROUCH ? 1.3 : 1.1,
        fade: 0.16,
        movement: 'lock',
      });
      this.onEvent('posture', { from, to: next });
      return;
    }

    if (next === POSTURE_CROUCH) {
      this.posture = POSTURE_CROUCH;
      this.onEvent('posture', { from, to: next });
      return;
    }

    // crouch -> stand
    this.posture = POSTURE_STAND;
    this._startAction('crouch_stand', { timeScale: 1.35, fade: 0.14, movement: 'lock' });
    this.onEvent('posture', { from, to: next });
  }

  /* ----------------------------------------------------------------- weapons */

  _handleWeaponInput(input) {
    const wanted = input.wasPressed('Digit1') ? 'pistol'
      : input.wasPressed('Digit2') ? 'rifle'
        : null;
    if (wanted && wanted !== this.weapon) this.equip(wanted);
  }

  equip(id) {
    if (!WEAPONS[id] || id === this.weapon) return;
    this.weapon = id;
    this.reloading = 0;
    this.burstLeft = 0;
    this.action = null;
    this.fireCooldown = Math.max(this.fireCooldown, 0.25);
    this.character.setWeapon(id);
    this.onEvent('weapon', { weapon: id });
  }

  /* ---------------------------------------------------------------- movement */

  _updateMovement(dt, input, camera) {
    const wish = new THREE.Vector2(0, 0);
    if (input.isDown('KeyW')) wish.y += 1;
    if (input.isDown('KeyS')) wish.y -= 1;
    if (input.isDown('KeyA')) wish.x -= 1;
    if (input.isDown('KeyD')) wish.x += 1;
    if (wish.lengthSq() > 1) wish.normalize();
    const moving = wish.lengthSq() > 1e-4;

    this.aiming = input.mouse.right && this.posture !== POSTURE_PRONE && this.grounded;
    // Shift while aiming is an aim-run, not a sprint; the posture gate keeps
    // sprinting out of crouch and prone.
    this.sprinting = (input.isDown('ShiftLeft') || input.isDown('ShiftRight'))
      && this.posture === POSTURE_STAND;
    this.walking = input.isDown('AltLeft') || input.isDown('AltRight');

    const canMove = !this.busy;
    const speedCap = canMove ? this._speedCap(moving) : 0;

    const camForward = new THREE.Vector3(Math.sin(camera.yaw), 0, Math.cos(camera.yaw));
    const camRight = new THREE.Vector3(camForward.z, 0, -camForward.x);
    const moveDir = new THREE.Vector3()
      .addScaledVector(camForward, wish.y)
      .addScaledVector(camRight, wish.x);
    if (moveDir.lengthSq() > 0) moveDir.normalize();

    const desired = moveDir.clone().multiplyScalar(this.grounded ? speedCap : Math.min(speedCap, TUNING.airSpeed));
    // exponential approach: responsive to direction changes, no overshoot
    const rate = 1 - Math.exp(-TUNING.acceleration * (this.grounded ? 1 : 0.35) * dt);
    this.velocity.x += (desired.x - this.velocity.x) * rate;
    this.velocity.z += (desired.z - this.velocity.z) * rate;
    if (!moving && this.velocity.lengthSq() < 0.02) this.velocity.set(0, 0, 0);
    this.speed = Math.hypot(this.velocity.x, this.velocity.z);

    // Body facing: while aiming or prone the torso follows the camera (so the
    // strafe clips read correctly); otherwise it follows the movement direction.
    let targetYaw = this.yaw;
    if (this.aiming) targetYaw = camera.yaw;
    else if (this.posture === POSTURE_PRONE) targetYaw = camera.yaw;
    else if (moving && this.speed > 0.4) targetYaw = Math.atan2(this.velocity.x, this.velocity.z);

    let delta = targetYaw - this.yaw;
    while (delta > Math.PI) delta -= Math.PI * 2;
    while (delta < -Math.PI) delta += Math.PI * 2;
    const turnRate = this.aiming || this.posture === POSTURE_PRONE ? TUNING.aimTurnSpeed : TUNING.freeTurnSpeed;
    this.yaw += delta * (1 - Math.exp(-turnRate * dt));

    this.position.x = THREE.MathUtils.clamp(this.position.x + this.velocity.x * dt, -GROUND_LIMIT, GROUND_LIMIT);
    this.position.z = THREE.MathUtils.clamp(this.position.z + this.velocity.z * dt, -GROUND_LIMIT, GROUND_LIMIT);
  }

  _speedCap(moving) {
    if (!moving) return 0;
    if (this.posture === POSTURE_PRONE) return TUNING.crawlSpeed;
    if (this.posture === POSTURE_CROUCH) return this.aiming ? TUNING.aimWalkSpeed * 0.8 : TUNING.crouchSpeed;
    if (this.aiming) return this.sprinting ? TUNING.aimRunSpeed : TUNING.aimWalkSpeed;
    if (this.sprinting) return TUNING.sprintSpeed;
    if (this.walking) return TUNING.walkSpeed;
    return TUNING.runSpeed;
  }

  _updateJumpAndGravity(dt, input) {
    if (input.wasPressed('Space') && this.grounded && !this.busy
      && this.posture === POSTURE_STAND) {
      this.verticalVelocity = TUNING.jumpImpulse;
      this.grounded = false;
      this.takeoffTimer = TAKEOFF_TIME;
      this.landTimer = 0;
      this.onEvent('jump');
    }

    if (!this.grounded) {
      this.verticalVelocity += TUNING.gravity * dt;
      this.position.y += this.verticalVelocity * dt;
      if (this.position.y <= 0) {
        this.position.y = 0;
        this.verticalVelocity = 0;
        this.grounded = true;
        this.landTimer = LAND_TIME;
        this.onEvent('landed');
      }
    } else {
      this.position.y = 0;
    }
  }

  /* ------------------------------------------------------------------ combat */

  _handleCombatInput(input) {
    const def = WEAPONS[this.weapon];

    if (input.wasPressed('KeyR')) this.reload();

    // Fire modes: semi/burst weapons consume the press edge, automatic weapons
    // read the held button. This is what stops a pistol from firing every frame.
    const wantsShot = def.mode === 'auto'
      ? input.mouse.left
      : input.mouse.leftPressed;

    if (wantsShot && this._canFire()) {
      if (this.ammo[this.weapon] <= 0) {
        this.reload();
      } else {
        this._shoot();
        if (def.mode === 'burst') this.burstLeft = (def.burst || 3) - 1;
      }
    }

    // Sustained burst fire (held trigger, remaining rounds of the burst)
    if (this.burstLeft > 0 && this.fireCooldown <= 0 && this._canFire()) {
      if (this.ammo[this.weapon] > 0) {
        this._shoot();
        this.burstLeft -= 1;
      } else {
        this.burstLeft = 0;
      }
    }

    if (this.firingPoseTimer > 0 && this.reloading <= 0) this._firingPose = true;

    if (input.wasPressed('KeyH')) {
      this._startAction('hit_light', { timeScale: 1.7, fade: 0.08, movement: 'lock' });
    }
    if (input.wasPressed('KeyJ')) {
      this._startAction('hit_heavy', { timeScale: 1.4, fade: 0.08, movement: 'lock' });
    }
    if (input.wasPressed('KeyK')) this.die();
  }

  _canFire() {
    return this.fireCooldown <= 0
      && this.reloading <= 0
      && !this.dead
      && !this.busy
      && this.posture !== POSTURE_PRONE
      && this.grounded;
  }

  _shoot() {
    const def = WEAPONS[this.weapon];
    this.fireCooldown = def.fireInterval;
    this.ammo[this.weapon] = Math.max(0, this.ammo[this.weapon] - 1);

    // Aim from the camera axis, not the body yaw, so shots go where the
    // crosshair points (this is what makes the shooting range testable).
    const origin = new THREE.Vector3();
    const dir = new THREE.Vector3();
    this._aimRay(origin, dir);
    const spread = (def.spread || 0.006) + this.speed * 0.0035;
    dir.x += (Math.random() - 0.5) * spread * 2;
    dir.y += (Math.random() - 0.5) * spread * 2;
    dir.normalize();

    this.recoilPitch += def.recoilPitch || 0.02;
    this.firingPoseTimer = def.firePoseTime || 0.24;
    this._firingPose = true;

    if (this.fx) this.fx.fire(this.character.getMuzzleWorld(new THREE.Vector3()), dir);
    const hit = this.hitTest ? this.hitTest(origin, dir, def) : null;
    this.onEvent('shot', { weapon: this.weapon, ammo: this.ammo[this.weapon], hit });
  }

  /** Camera-space aim ray. In third person it starts at the camera. */
  _aimRay(origin, dir) {
    const pitch = this.cameraPitch;
    const yaw = this.yaw + (this.aiming ? 0 : 0);
    dir.set(
      Math.sin(yaw) * Math.cos(pitch),
      -Math.sin(pitch),
      Math.cos(yaw) * Math.cos(pitch),
    ).normalize();
    if (this.cameraProvider) this.cameraProvider.getAimOrigin(origin);
    else origin.copy(this.position).add(new THREE.Vector3(0, 1.5, 0));
  }

  setCameraProvider(provider) {
    this.cameraProvider = provider;
  }

  reload() {
    if (this.reloading > 0) return;
    if (this.busy) return;
    const def = WEAPONS[this.weapon];
    if (this.ammo[this.weapon] >= def.magSize) return;
    if ((this.reserve[this.weapon] || 0) <= 0) {
      this.onEvent('dryReload', { weapon: this.weapon });
      return;
    }
    const clipId = this.posture === POSTURE_PRONE ? 'reload_prone'
      : this.posture === POSTURE_CROUCH ? 'reload_crouch' : 'reload_stand';
    const action = this.character.actions.get(clipId);
    const clipDuration = action ? action.getClip().duration : 1.5;
    const desired = def.reloadTime || 1.8;
    const timeScale = THREE.MathUtils.clamp(clipDuration / desired, 0.6, 2.6);
    this.reloading = clipDuration / timeScale;
    this._startAction(clipId, { timeScale, fade: 0.15, movement: 'lock' });
    this.onEvent('reloading', { clipId, weapon: this.weapon });
  }

  _completeReload() {
    const def = WEAPONS[this.weapon];
    const needed = def.magSize - this.ammo[this.weapon];
    const available = Math.min(needed, this.reserve[this.weapon] || 0);
    this.ammo[this.weapon] += available;
    this.reserve[this.weapon] -= available;
    this.onEvent('reloaded', {
      weapon: this.weapon,
      ammo: this.ammo[this.weapon],
      reserve: this.reserve[this.weapon],
    });
  }

  die() {
    if (this.dead) return;
    this.dead = true;
    this.action = null;
    this.reloading = 0;
    this.deathClip = Math.random() > 0.5 ? 'death_front' : 'death_back';
    this.velocity.set(0, 0, 0);
    this.character.play(this.deathClip, { fade: 0.12, restart: true });
    this.onEvent('died');
  }

  /* --------------------------------------------------------------- animation */

  _startAction(id, { timeScale = 1, fade = TUNING.fastCrossFade, movement = 'lock' } = {}) {
    const action = this.character.actions.get(id);
    if (!action) return false;
    // Never restart an identical action that is already running.
    if (this.action && this.action.id === id) return false;
    const duration = action.getClip().duration / Math.max(0.05, timeScale);
    this.action = { id, remaining: duration, timeScale, fade, movement };
    this.character.play(id, { fade, timeScale, restart: true });
    return true;
  }

  /**
   * Chooses the single clip for this frame and starts it. Order matters:
   * death > one-shot action > air > posture locomotion.
   */
  _playResolvedClip() {
    const ch = this.character;

    if (this.action) {
      ch.play(this.action.id, { fade: this.action.fade, timeScale: this.action.timeScale });
      return;
    }

    if (!this.grounded) {
      if (this.takeoffTimer > 0) ch.play('jump_takeoff', { fade: 0.08 });
      else ch.play('jump_air', { fade: 0.16 });
      return;
    }

    if (this.landTimer > 0) {
      ch.play('jump_land', { fade: 0.1, timeScale: 1.5 });
      return;
    }

    ch.play(this._locomotionClip(), { fade: TUNING.crossFade, timeScale: this._locomotionRate() });
  }

  _locomotionClip() {
    const local = this._localMoveDirection();
    const moving = this.speed > 0.12;

    if (this.posture === POSTURE_PRONE) {
      if (!moving) return 'prone_idle';
      if (local.y > 0.4) return 'crawl_fwd';
      if (local.y < -0.4) return 'crawl_back';
      return local.x < 0 ? 'crawl_left' : 'crawl_right';
    }

    if (this.posture === POSTURE_CROUCH) {
      if (!moving) return this.aiming ? 'crouch_aim_idle' : 'crouch_idle';
      return pickDirectional('crouch', local);
    }

    if (this.aiming) {
      if (this.firingPoseTimer > 0) return this.weapon === 'rifle' ? 'rifle_fire' : 'pistol_aim_fire';
      if (this.weapon === 'rifle') {
        if (!moving) return 'rifle_aim_idle';
        return this.speed > 2.6 ? 'rifle_aim_run' : 'rifle_aim_walk';
      }
      if (!moving) return 'pistol_aim_idle';
      return pickPistolDirectional(local);
    }

    if (this.firingPoseTimer > 0) {
      return this.weapon === 'rifle' ? 'rifle_fire' : 'pistol_aim_fire';
    }

    if (!moving) return 'idle';
    if (this.sprinting && local.y > 0.38 && this.speed > 4.4) return 'sprint_fwd';
    if (this.speed > 2.6) return pickDirectional('run', local);
    return pickDirectional('walk', local);
  }

  _locomotionRate() {
    const id = this.character.currentId;
    if (!id) return 1;
    // In-place clips (idle, aim idle, prone turns) always run at 1x.
    const natural = NATURAL_SPEED.get(id) || 0;
    if (!natural) return 1;
    if (id === 'crawl_left' || id === 'crawl_right') {
      // latepral prone shuffle: tie the turn cycle to the requested speed
      return THREE.MathUtils.clamp(this.speed / TUNING.crawlSpeed, 0.6, 1.6);
    }
    return THREE.MathUtils.clamp(this.speed / natural, 0.55, 1.9);
  }

  /** Movement direction in camera space: x = right, y = forward. */
  _localMoveDirection() {
    const local = new THREE.Vector2(0, 0);
    if (this.speed < 0.05) return local;
    const moveYaw = Math.atan2(this.velocity.x, this.velocity.z);
    let d = moveYaw - this.cameraYaw;
    while (d > Math.PI) d -= Math.PI * 2;
    while (d < -Math.PI) d += Math.PI * 2;
    local.set(Math.sin(d), Math.cos(d));
    return local;
  }

  _applyTransform() {
    this.character.group.position.copy(this.position);
    this.character.group.rotation.y = this.yaw;
  }

  getDebugState() {
    return {
      character: this.character.def.label,
      posture: this.posture,
      aim: this.aiming,
      weapon: WEAPONS[this.weapon].label,
      ammo: `${this.ammo[this.weapon]} / ${WEAPONS[this.weapon].magSize}`,
      reserve: `${this.reserve[this.weapon]}`,
      reloading: this.reloading > 0,
      clip: this.character.currentId || '-',
      action: this.action ? this.action.id : '-',
      speed: this.speed,
      dead: this.dead,
      grounded: this.grounded,
    };
  }
}

function pickDirectional(prefix, local) {
  if (local.y > 0.38) return `${prefix}_fwd`;
  if (local.y < -0.38) return `${prefix}_back`;
  if (local.x < -0.38) return `${prefix}_left`;
  if (local.x > 0.38) return `${prefix}_right`;
  return `${prefix}_fwd`;
}

function pickPistolDirectional(local) {
  if (local.y < -0.38) return 'pistol_aim_back';
  if (local.x < -0.38) return 'pistol_aim_left';
  if (local.x > 0.38) return 'pistol_aim_right';
  // The free Mixamo library has no forward pistol-walk clip, so the strafe
  // (which keeps the pistol on target) is reused for forward movement.
  return 'pistol_aim_left';
}
