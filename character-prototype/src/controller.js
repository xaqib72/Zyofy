import * as THREE from 'three';
import { TUNING, CLIP_DEFS } from './config.js';
import { WEAPONS, WEAPON_LIST, WEAPON_ORDER, DEFAULT_WEAPON } from './weaponCatalog.js';

/**
 * Movement, posture, camera-aware stance and weapon state for one character.
 *
 * Invariants this file is responsible for (each one has a regression test):
 *   1. exactly one clip is requested per frame;
 *   2. one-shot actions live in a single slot and can never stack;
 *   3. mutually exclusive postures can't overlap (no jumping while prone, no
 *      posture change while airborne, no weapon fire while reloading);
 *   4. trigger semantics follow the weapon: auto = held button, semi/burst =
 *      press edge, bolt/pump = one shot then a forced cycle, melee = swing;
 *   5. ammunition accounting is exact and reserve depletes.
 */

const POSTURE_STAND = 'stand';
const POSTURE_CROUCH = 'crouch';
const POSTURE_PRONE = 'prone';

const GROUND_LIMIT = 24;
const NATURAL_SPEED = new Map(CLIP_DEFS.map((d) => [d.id, d.naturalSpeed || 0]));
const TAKEOFF_TIME = 0.16;
const LAND_TIME = 0.18;

export class CharacterController {
  constructor(character, { fx, onEvent, hitTest, aimProbe } = {}) {
    this.character = character;
    this.fx = fx;
    this.onEvent = onEvent || (() => {});
    this.hitTest = hitTest || null;
    this.aimProbe = aimProbe || null;

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

    this.weapon = DEFAULT_WEAPON;
    this.ammo = {};
    this.reserve = {};
    for (const def of WEAPON_LIST) {
      this.ammo[def.id] = def.magSize;
      this.reserve[def.id] = def.reserve;
    }

    this.fireCooldown = 0;
    this.burstLeft = 0;
    this.cycleTimer = 0;          // bolt / pump action in progress
    this.reloading = 0;
    this.reloadDuration = 0;

    this.dead = false;
    this.deathClip = 'death_front';
    this.action = null;
    this.takeoffTimer = 0;
    this.landTimer = 0;

    this.cameraYaw = Math.PI;
    this.cameraPitch = 0;
    this.recoilPitch = 0;
    this.firingPoseTimer = 0;
    this.mode = 'tps';

    this.shotsFired = 0;
    this.meleeSwings = 0;

    this.character.setWeapon(this.weapon);
  }

  get def() {
    return WEAPONS[this.weapon];
  }

  get isMelee() {
    return this.def.mode === 'melee';
  }

  /* ------------------------------------------------------------------ state */

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

  setCameraProvider(provider) {
    this.cameraProvider = provider;
  }

  get busy() {
    return !!(this.action && this.action.movement === 'lock');
  }

  /* ------------------------------------------------------------------ frame */

  update(dt, input, camera) {
    dt = Math.min(dt, 0.05);
    this.cameraYaw = camera.yaw;
    this.cameraPitch = camera.pitch;
    this.mode = camera.mode || 'tps';

    this._tickTimers(dt);

    if (this.dead) {
      this._updateDead(input);
      this._applyTransform();
      return;
    }

    this._handlePostureInput(input);
    this._handleWeaponInput(input);
    this._updateMovement(dt, input, camera);
    this._updateJumpAndGravity(dt, input);
    this._handleCombatInput(input);
    this._playResolvedClip();
    this.character.updateWeaponMechanics(dt, this);
    this._applyTransform();
  }

  _tickTimers(dt) {
    this.fireCooldown = Math.max(0, this.fireCooldown - dt);
    this.recoilPitch *= Math.pow(0.0015, dt);
    this.firingPoseTimer = Math.max(0, this.firingPoseTimer - dt);
    this.takeoffTimer = Math.max(0, this.takeoffTimer - dt);
    this.landTimer = Math.max(0, this.landTimer - dt);
    this.cycleTimer = Math.max(0, this.cycleTimer - dt);

    if (this.action) {
      this.action.remaining -= dt;
      if (this.action.remaining <= 0) {
        const id = this.action.id;
        this.action = null;
        this.onEvent('actionEnd', { id });
      }
    }

    if (this.reloading > 0) {
      this.reloading = Math.max(0, this.reloading - dt);
      if (this.reloading === 0) this._completeReload();
    }
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

  /* ---------------------------------------------------------------- posture */

  _handlePostureInput(input) {
    const ctrlDown = input.wasPressed('ControlLeft') || input.wasPressed('ControlRight');
    const proneDown = input.wasPressed('KeyZ');
    if (!ctrlDown && !proneDown) return;

    const canChange = this.grounded && !this.busy;
    if (!canChange) return;

    if (proneDown) {
      this._enterPosture(this.posture === POSTURE_PRONE ? POSTURE_STAND : POSTURE_PRONE);
      return;
    }
    this._enterPosture(this.posture === POSTURE_CROUCH ? POSTURE_STAND : POSTURE_CROUCH);
  }

  _enterPosture(next) {
    if (next === this.posture) return;
    const from = this.posture;

    if (next === POSTURE_PRONE) {
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
        timeScale: next === POSTURE_CROUCH ? 1.3 : 1.1, fade: 0.16, movement: 'lock',
      });
      this.onEvent('posture', { from, to: next });
      return;
    }
    if (next === POSTURE_CROUCH) {
      this.posture = POSTURE_CROUCH;
      this.onEvent('posture', { from, to: next });
      return;
    }
    this.posture = POSTURE_STAND;
    this._startAction('crouch_stand', { timeScale: 1.35, fade: 0.14, movement: 'lock' });
    this.onEvent('posture', { from, to: next });
  }

  /* ---------------------------------------------------------------- weapons */

  _handleWeaponInput(input) {
    if (input.wasPressed('KeyQ') || input.mouse.wheel > 0) this.cycleWeapon(1);
    if (input.wasPressed('KeyE')) this.refillAll();

    for (let i = 1; i <= 9; i += 1) {
      if (input.wasPressed(`Digit${i}`)) {
        const id = WEAPON_ORDER[i - 1];
        if (id) this.equip(id);
      }
    }
  }

  cycleWeapon(direction) {
    const idx = WEAPON_ORDER.indexOf(this.weapon);
    const next = WEAPON_ORDER[(idx + direction + WEAPON_ORDER.length) % WEAPON_ORDER.length];
    this.equip(next);
  }

  equip(id) {
    if (!WEAPONS[id] || id === this.weapon) return;
    const previous = this.weapon;
    this.weapon = id;
    this.reloading = 0;
    this.reloadDuration = 0;
    this.burstLeft = 0;
    this.cycleTimer = 0;
    this.action = null;
    this.fireCooldown = 0.28;
    this.character.setWeapon(id);

    // Draw animation: long guns and handguns have their own clips.
    const def = WEAPONS[id];
    if (def.mode !== 'melee') {
      const clip = def.firePose === 'long' ? 'draw_long' : 'draw_short';
      this._startAction(clip, { timeScale: 1.6, fade: 0.1, movement: 'lock' });
    }
    this.onEvent('weapon', { weapon: id, previous });
  }

  refillAll() {
    for (const def of WEAPON_LIST) {
      this.ammo[def.id] = def.magSize;
      this.reserve[def.id] = def.reserve;
    }
    this.onEvent('refilled');
  }

  /* --------------------------------------------------------------- movement */

  _updateMovement(dt, input, camera) {
    const wish = new THREE.Vector2(0, 0);
    if (input.isDown('KeyW')) wish.y += 1;
    if (input.isDown('KeyS')) wish.y -= 1;
    if (input.isDown('KeyA')) wish.x -= 1;
    if (input.isDown('KeyD')) wish.x += 1;
    if (wish.lengthSq() > 1) wish.normalize();
    const moving = wish.lengthSq() > 1e-4;

    this.aiming = input.mouse.right && this.posture !== POSTURE_PRONE && this.grounded;
    this.sprinting = (input.isDown('ShiftLeft') || input.isDown('ShiftRight'))
      && this.posture === POSTURE_STAND;
    this.walking = input.isDown('AltLeft') || input.isDown('AltRight');

    const canMove = !this.busy;
    const cap = canMove ? this._speedCap(moving) : 0;

    const camForward = new THREE.Vector3(Math.sin(camera.yaw), 0, Math.cos(camera.yaw));
    const camRight = new THREE.Vector3(camForward.z, 0, -camForward.x);
    const moveDir = new THREE.Vector3()
      .addScaledVector(camForward, wish.y)
      .addScaledVector(camRight, wish.x);
    if (moveDir.lengthSq() > 0) moveDir.normalize();

    const desired = moveDir.clone().multiplyScalar(this.grounded ? cap : Math.min(cap, TUNING.airSpeed));
    const rate = 1 - Math.exp(-TUNING.acceleration * (this.grounded ? 1 : 0.35) * dt);
    this.velocity.x += (desired.x - this.velocity.x) * rate;
    this.velocity.z += (desired.z - this.velocity.z) * rate;
    if (!moving && this.velocity.lengthSq() < 0.02) this.velocity.set(0, 0, 0);
    this.speed = Math.hypot(this.velocity.x, this.velocity.z);

    let targetYaw = this.yaw;
    if (this.mode === 'fps') targetYaw = camera.yaw;                  // arms must follow the view
    else if (this.aiming) targetYaw = camera.yaw;
    else if (this.posture === POSTURE_PRONE) targetYaw = camera.yaw;
    else if (moving && this.speed > 0.4) targetYaw = Math.atan2(this.velocity.x, this.velocity.z);

    let delta = targetYaw - this.yaw;
    while (delta > Math.PI) delta -= Math.PI * 2;
    while (delta < -Math.PI) delta += Math.PI * 2;
    const turnRate = this.mode === 'fps' ? 24
      : this.aiming || this.posture === POSTURE_PRONE ? TUNING.aimTurnSpeed : TUNING.freeTurnSpeed;
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
    if (input.wasPressed('Space') && this.grounded && !this.busy && this.posture === POSTURE_STAND) {
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

  /* ----------------------------------------------------------------- combat */

  _handleCombatInput(input) {
    const def = this.def;

    if (!this.isMelee && input.wasPressed('KeyR')) this.reload();

    const trigger = def.mode === 'auto' || def.mode === 'pump'
      ? input.mouse.left
      : input.mouse.leftPressed;

    if (trigger && this._canFire()) {
      if (!this.isMelee && this.ammo[this.weapon] <= 0) {
        this.reload();
      } else {
        this._fireOnce();
        if (def.mode === 'burst') this.burstLeft = (def.burst || 3) - 1;
      }
    }

    if (this.burstLeft > 0 && this.fireCooldown <= 0 && this._canFire()) {
      if (this.ammo[this.weapon] > 0) {
        this._fireOnce();
        this.burstLeft -= 1;
      } else {
        this.burstLeft = 0;
      }
    }

    if (input.wasPressed('KeyH')) this._startAction('hit_light', { timeScale: 1.7, fade: 0.08, movement: 'lock' });
    if (input.wasPressed('KeyJ')) this._startAction('hit_heavy', { timeScale: 1.4, fade: 0.08, movement: 'lock' });
    if (input.wasPressed('KeyK')) this.die();
  }

  _canFire() {
    return this.fireCooldown <= 0
      && this.cycleTimer <= 0
      && this.reloading <= 0
      && !this.dead
      && !this.busy
      && this.grounded
      && (this.isMelee || this.posture !== POSTURE_PRONE);
  }

  _aimRay(origin, direction) {
    if (this.cameraProvider && this.cameraProvider.getAimDirection) {
      this.cameraProvider.getAimOrigin(origin);
      this.cameraProvider.getAimDirection(direction);
      return;
    }
    const pitch = this.cameraPitch;
    direction.set(
      Math.sin(this.yaw) * Math.cos(pitch),
      -Math.sin(pitch),
      Math.cos(this.yaw) * Math.cos(pitch),
    ).normalize();
    origin.copy(this.position).setY(1.5);
  }

  _fireOnce() {
    const def = this.def;
    const interval = 60 / def.rpm;
    this.fireCooldown = interval;
    this.shotsFired += 1;

    if (this.isMelee) {
      this._meleeAttack();
      return;
    }

    this.ammo[this.weapon] = Math.max(0, this.ammo[this.weapon] - 1);

    const origin = new THREE.Vector3();
    const dir = new THREE.Vector3();
    this._aimRay(origin, dir);

    const spreadBase = def.spread * (this.aiming ? 0.55 : 1) + this.speed * 0.0025;
    const muzzle = this.character.getMuzzleWorld(new THREE.Vector3());
    let hit = null;

    const pellets = def.pellets || 1;
    for (let i = 0; i < pellets; i += 1) {
      const shotDir = dir.clone();
      shotDir.x += (Math.random() - 0.5) * spreadBase * 2;
      shotDir.y += (Math.random() - 0.5) * spreadBase * 2;
      shotDir.z += (Math.random() - 0.5) * spreadBase * 2;
      shotDir.normalize();
      const result = this.hitTest ? this.hitTest(origin, shotDir, def) : null;
      if (result && this.fx) this.fx.impact(result.point, result.normal);
      if (result && (!hit || result.damage > hit.damage)) hit = result;
      if (this.fx) {
        this.fx.fire(muzzle, shotDir, {
          tracerLength: Math.min(def.range, 120),
          scale: def.pellets > 1 ? 1.5 : 1,
        });
      }
    }

    this.recoilPitch += def.recoil.pitch * (this.aiming ? 0.8 : 1);
    this.firingPoseTimer = def.mode === 'bolt' ? 0.5 : 0.22;

    // Bolt-action and pump-action weapons must cycle before the next shot; the
    // cycle has a visible mechanical animation on the weapon itself.
    if (def.mode === 'bolt' || def.mode === 'pump') {
      this.cycleTimer = def.cycle;
      this.character.cycleWeaponPart(def.mode, def.cycle);
    }

    this.onEvent('shot', { weapon: this.weapon, ammo: this.ammo[this.weapon], hit, pellets });
  }

  _meleeAttack() {
    const def = this.def;
    const clip = def.model.machete ? 'melee_slash' : 'melee_stab';
    this._startAction(clip, { timeScale: def.rpm / 40, fade: 0.1, movement: 'lock' });
    this.meleeSwings += 1;

    // Melee reach is measured from the blade, not the camera, otherwise a
    // third-person camera 4 m behind would make every swing fall short. The
    // blade is aimed at the point the camera is looking at, so the swing lands
    // where the crosshair is instead of parallel to it.
    const origin = this.character.getMuzzleWorld(new THREE.Vector3());
    const dir = new THREE.Vector3();
    if (this.cameraProvider && this.cameraProvider.getAimDirection) {
      const camOrigin = this.cameraProvider.getAimOrigin(new THREE.Vector3());
      this.cameraProvider.getAimDirection(dir);
      const pivot = this.character.bones.hips
        ? this.character.bones.hips.getWorldPosition(new THREE.Vector3())
        : this.position.clone();
      // If the crosshair is on something, swing at that exact point.
      const probed = this.aimProbe ? this.aimProbe(camOrigin, dir, 60) : null;
      const aimPoint = probed || camOrigin.clone().addScaledVector(
        dir, camOrigin.distanceTo(pivot) + def.range * 0.85,
      );
      dir.copy(aimPoint).sub(origin).normalize();
    } else {
      dir.set(Math.sin(this.yaw), 0, Math.cos(this.yaw));
    }
    const result = this.hitTest ? this.hitTest(origin, dir, { ...def, range: def.range }) : null;
    if (result) {
      if (this.fx) this.fx.impact(result.point, result.normal);
      this.onEvent('melee', { weapon: this.weapon, hit: result });
    } else {
      this.onEvent('melee', { weapon: this.weapon, hit: null });
    }
  }

  reload() {
    const def = this.def;
    if (this.isMelee || def.reloadStyle === 'none') return;
    if (this.reloading > 0 || this.busy) return;
    if (this.ammo[this.weapon] >= def.magSize) return;
    if ((this.reserve[this.weapon] || 0) <= 0) {
      this.onEvent('dryReload', { weapon: this.weapon });
      return;
    }

    const clipId = this.posture === POSTURE_PRONE ? 'reload_prone'
      : this.posture === POSTURE_CROUCH ? 'reload_crouch' : 'reload_stand';
    const action = this.character.actions.get(clipId);
    const clipDuration = action ? action.getClip().duration : 2;
    const timeScale = THREE.MathUtils.clamp(clipDuration / def.reloadTime, 0.6, 2.8);
    this.reloading = clipDuration / timeScale;
    this.reloadDuration = this.reloading;
    this._startAction(clipId, { timeScale, fade: 0.15, movement: 'lock' });
    this.character.beginReloadMechanics(def.reloadStyle, this.reloading);
    this.onEvent('reloading', { clipId, weapon: this.weapon, duration: this.reloading });
  }

  _completeReload() {
    const def = this.def;
    const needed = def.magSize - this.ammo[this.weapon];
    const available = Math.min(needed, this.reserve[this.weapon] || 0);
    this.ammo[this.weapon] += available;
    this.reserve[this.weapon] -= available;
    this.character.endReloadMechanics();
    this.onEvent('reloaded', {
      weapon: this.weapon, ammo: this.ammo[this.weapon], reserve: this.reserve[this.weapon],
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

  /* -------------------------------------------------------------- animation */

  _startAction(id, { timeScale = 1, fade = TUNING.fastCrossFade, movement = 'lock' } = {}) {
    const action = this.character.actions.get(id);
    if (!action) return false;
    if (this.action && this.action.id === id && movement === 'lock') return false;
    const duration = action.getClip().duration / Math.max(0.05, timeScale);
    this.action = { id, remaining: duration, timeScale, fade, movement };
    this.character.play(id, { fade, timeScale, restart: true });
    return true;
  }

  _playResolvedClip() {
    const ch = this.character;

    if (this.action) {
      ch.play(this.action.id, { fade: this.action.fade, timeScale: this.action.timeScale });
      return;
    }
    if (!this.grounded) {
      ch.play(this.takeoffTimer > 0 ? 'jump_takeoff' : 'jump_air', { fade: this.takeoffTimer > 0 ? 0.08 : 0.16 });
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
    const pose = this.def.firePose;

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

    if (pose === 'melee') {
      if (!moving) return 'knife_idle';
      return this.speed > 2.6 ? pickDirectional('run', local) : pickDirectional('walk', local);
    }

    // In first person the weapon must stay shouldered or the barrel points
    // across the screen: the aim family of clips is the "weapon ready" pose.
    const weaponUp = this.aiming || this.mode === 'fps';

    if (weaponUp) {
      if (this.firingPoseTimer > 0) return pose === 'long' ? 'rifle_fire' : 'pistol_aim_fire';
      if (pose === 'long') {
        if (!moving) return 'rifle_aim_idle';
        return this.speed > 2.6 ? 'rifle_aim_run' : 'rifle_aim_walk';
      }
      if (!moving) return 'pistol_aim_idle';
      return pickPistolDirectional(local);
    }

    if (this.firingPoseTimer > 0) return pose === 'long' ? 'rifle_fire' : 'pistol_aim_fire';

    if (!moving) return pose === 'melee' ? 'knife_idle' : 'idle';
    if (this.sprinting && local.y > 0.38 && this.speed > 4.4) return 'sprint_fwd';
    if (this.speed > 2.6) return pickDirectional('run', local);
    return pickDirectional('walk', local);
  }

  _locomotionRate() {
    const id = this.character.currentId;
    if (!id) return 1;
    const natural = NATURAL_SPEED.get(id) || 0;
    if (!natural) return 1;
    if (id === 'crawl_left' || id === 'crawl_right') {
      return THREE.MathUtils.clamp(this.speed / TUNING.crawlSpeed, 0.6, 1.6);
    }
    return THREE.MathUtils.clamp(this.speed / natural, 0.55, 1.9);
  }

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
    // Per-rig correction so a lying body rests ON the ground plane instead of
    // sinking into it (the two rigs have different torso proportions).
    if (this.posture === POSTURE_PRONE) {
      this.character.group.position.y += this.character.def.proneLift || 0;
    }
    this.character.group.rotation.y = this.yaw;
  }

  getDebugState() {
    const def = this.def;
    return {
      character: this.character.def.label,
      posture: this.posture,
      aim: this.aiming,
      weapon: def.label,
      weaponId: this.weapon,
      weaponIndex: WEAPON_ORDER.indexOf(this.weapon) + 1,
      weaponCount: WEAPON_ORDER.length,
      ammo: this.isMelee ? 'melee' : `${this.ammo[this.weapon]} / ${this.reserve[this.weapon]}`,
      reserve: `${this.reserve[this.weapon] ?? 0}`,
      reloading: this.reloading > 0,
      clip: this.character.currentId || '-',
      action: this.action ? this.action.id : '-',
      speed: this.speed,
      dead: this.dead,
      grounded: this.grounded,
      mode: this.mode,
      cycling: this.cycleTimer > 0,
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
  return 'pistol_aim_left';
}
