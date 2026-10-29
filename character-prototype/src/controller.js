import * as THREE from 'three';
import { TUNING, WEAPONS } from './config.js';

const POSTURE_STAND = 'stand';
const POSTURE_CROUCH = 'crouch';
const POSTURE_PRONE = 'prone';

const GROUND_LIMIT = 24;

/**
 * Movement + animation state machine for one character.
 *
 * Animation coverage
 *   stand  : idle / walk / run / sprint (body turns towards the move direction)
 *   aim    : rifle -> aim idle, aim walk, aim run | pistol -> aim idle + strafes
 *   crouch : idle, 4-way crouch walk (body turns, or faces the camera while aiming)
 *   prone  : prone idle, crawl forward, crawl backward
 *   action : jump take-off, airborne loop, landing, fire, reload, hit, death, get up
 */
export class CharacterController {
  constructor(character, { fx, onEvent } = {}) {
    this.character = character;
    this.fx = fx;
    this.onEvent = onEvent || (() => {});

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
    this.ammo = { rifle: WEAPONS.rifle.magSize, pistol: WEAPONS.pistol.magSize };
    this.fireCooldown = 0;

    this.dead = false;
    this.deathClip = 'death_front';
    this.oneShot = null;
    this.reloading = 0;

    this.cameraYaw = Math.PI;
    this.cameraPitch = 0;
    this.recoilPitch = 0;
    this.firingPoseTimer = 0;

    this.character.setWeapon(this.weapon);
  }

  /** Keeps posture/pose when Tab swaps the visible character. */
  transferStateFrom(other) {
    this.posture = other.posture;
    this.aiming = other.aiming;
    this.weapon = other.weapon;
    this.ammo = { ...other.ammo };
    this.position.copy(other.position);
    this.velocity.copy(other.velocity);
    this.yaw = other.yaw;
    this.dead = other.dead;
    this.deathClip = other.deathClip;
    this.cameraYaw = other.cameraYaw;
    this.cameraPitch = other.cameraPitch;
    this.character.setWeapon(this.weapon);
    this._applyTransform();
  }

  _playOnce(id, { timeScale = 1, fade = TUNING.fastCrossFade, lock = true } = {}) {
    const action = this.character.actions.get(id);
    if (!action) return;
    const duration = action.getClip().duration / Math.max(0.05, timeScale);
    this.oneShot = { id, remaining: duration, timeScale, fade, lock };
    this.character.play(id, { fade, timeScale, restart: true });
  }

  _setPosture(next) {
    if (this.posture === next) return;

    if (next === POSTURE_PRONE) {
      this.posture = POSTURE_PRONE;
      this.aiming = false;
      this.character.play('prone_idle', { fade: 0.32 });
      return;
    }
    if (this.posture === POSTURE_PRONE) {
      this.posture = next;
      this._playOnce('prone_stand', { timeScale: next === POSTURE_CROUCH ? 1.35 : 1.05, fade: 0.18 });
      return;
    }
    if (next === POSTURE_CROUCH) {
      this.posture = POSTURE_CROUCH;
      this.character.play('crouch_idle', { fade: 0.25 });
      return;
    }
    this.posture = POSTURE_STAND;
    this._playOnce('crouch_stand', { timeScale: 1.35, fade: 0.16 });
  }

  update(dt, input, camera) {
    dt = Math.min(dt, 0.05);
    this.cameraYaw = camera.yaw;
    this.cameraPitch = camera.pitch;

    // ------------------------------------------------------------------ timers
    this.fireCooldown = Math.max(0, this.fireCooldown - dt);
    this.recoilPitch *= Math.pow(0.0015, dt);
    this.firingPoseTimer = Math.max(0, this.firingPoseTimer - dt);
    if (this.oneShot) {
      this.oneShot.remaining -= dt;
      if (this.oneShot.remaining <= 0) this.oneShot = null;
    }
    if (this.reloading > 0) {
      this.reloading -= dt;
      if (this.reloading <= 0) {
        this.ammo[this.weapon] = WEAPONS[this.weapon].magSize;
        this.onEvent('reloaded', { weapon: this.weapon });
      }
    }

    // ------------------------------------------------------------------- death
    if (this.dead) {
      this.velocity.set(0, 0, 0);
      this.speed = 0;
      this.character.play(this.deathClip, { fade: 0.12 });
      if (input.wasPressed('KeyG')) {
        this.dead = false;
        this.posture = POSTURE_STAND;
        this._playOnce('prone_stand', { timeScale: 1.1, fade: 0.2 });
        this.onEvent('revived');
      }
      this._applyTransform();
      return;
    }

    // ---------------------------------------------------------------- postures
    if (input.wasPressed('ControlLeft') || input.wasPressed('ControlRight')) {
      if (this.posture === POSTURE_STAND) this._setPosture(POSTURE_CROUCH);
      else if (this.posture === POSTURE_CROUCH) this._setPosture(POSTURE_STAND);
      else this._setPosture(POSTURE_CROUCH);
    }
    if (input.wasPressed('KeyZ')) {
      this._setPosture(this.posture === POSTURE_PRONE ? POSTURE_STAND : POSTURE_PRONE);
    }
    if (input.wasPressed('Digit1') && this.weapon !== 'pistol') {
      this.weapon = 'pistol';
      this.character.setWeapon('pistol');
      this.reloading = 0;
      this.onEvent('weapon', { weapon: this.weapon });
    }
    if (input.wasPressed('Digit2') && this.weapon !== 'rifle') {
      this.weapon = 'rifle';
      this.character.setWeapon('rifle');
      this.reloading = 0;
      this.onEvent('weapon', { weapon: this.weapon });
    }

    // ---------------------------------------------------------------- movement
    const wish = new THREE.Vector2(0, 0);
    if (input.isDown('KeyW')) wish.y += 1;
    if (input.isDown('KeyS')) wish.y -= 1;
    if (input.isDown('KeyA')) wish.x -= 1;
    if (input.isDown('KeyD')) wish.x += 1;
    if (wish.lengthSq() > 1) wish.normalize();
    const moving = wish.lengthSq() > 0.0001;

    this.aiming = input.mouse.right && this.posture !== POSTURE_PRONE;
    this.sprinting = input.isDown('ShiftLeft') || input.isDown('ShiftRight');
    this.walking = input.isDown('AltLeft') || input.isDown('AltRight');

    const camForward = new THREE.Vector3(Math.sin(camera.yaw), 0, Math.cos(camera.yaw));
    const camRight = new THREE.Vector3(camForward.z, 0, -camForward.x);
    const moveDir = new THREE.Vector3()
      .addScaledVector(camForward, wish.y)
      .addScaledVector(camRight, wish.x);
    if (moveDir.lengthSq() > 0) moveDir.normalize();

    let targetSpeed = 0;
    if (moving) {
      if (this.posture === POSTURE_PRONE) targetSpeed = TUNING.crawlSpeed;
      else if (this.posture === POSTURE_CROUCH) targetSpeed = this.aiming ? TUNING.aimWalkSpeed * 0.85 : TUNING.crouchSpeed;
      else if (this.aiming) targetSpeed = this.sprinting ? TUNING.aimRunSpeed : TUNING.aimWalkSpeed;
      else if (this.sprinting) targetSpeed = TUNING.sprintSpeed;
      else if (this.walking) targetSpeed = TUNING.walkSpeed;
      else targetSpeed = TUNING.runSpeed;
    }

    const desiredVel = moveDir.clone().multiplyScalar(targetSpeed);
    const accel = TUNING.acceleration * (this.grounded ? 1 : 0.4);
    this.velocity.x += (desiredVel.x - this.velocity.x) * Math.min(1, accel * dt);
    this.velocity.z += (desiredVel.z - this.velocity.z) * Math.min(1, accel * dt);
    if (!moving && this.velocity.lengthSq() < 0.02) this.velocity.set(0, 0, 0);
    this.speed = Math.hypot(this.velocity.x, this.velocity.z);

    // body orientation
    // Third-person shooter style: while moving, aiming or prone the body follows
    // the camera so the directional (strafe) locomotion clips read correctly.
    // Standing still, the body keeps its last facing instead of spinning with the camera.
    let targetYaw = this.yaw;
    if (this.aiming || this.posture === POSTURE_PRONE || moving) targetYaw = camera.yaw;
    let delta = targetYaw - this.yaw;
    while (delta > Math.PI) delta -= Math.PI * 2;
    while (delta < -Math.PI) delta += Math.PI * 2;
    const turnRate = this.aiming ? TUNING.aimTurnSpeed : TUNING.freeTurnSpeed;
    this.yaw += delta * Math.min(1, turnRate * dt);

    // jump
    const busy = this.oneShot ? this.oneShot.lock : false;
    if (input.wasPressed('Space') && this.grounded && this.posture === POSTURE_STAND && !busy) {
      this.verticalVelocity = TUNING.jumpImpulse;
      this.grounded = false;
      this._playOnce('jump_takeoff', { timeScale: 1.0, fade: 0.08, lock: false });
      this.onEvent('jump');
    }

    // integrate
    this.position.x = THREE.MathUtils.clamp(this.position.x + this.velocity.x * dt, -GROUND_LIMIT, GROUND_LIMIT);
    this.position.z = THREE.MathUtils.clamp(this.position.z + this.velocity.z * dt, -GROUND_LIMIT, GROUND_LIMIT);

    if (!this.grounded) {
      this.verticalVelocity += TUNING.gravity * dt;
      this.position.y += this.verticalVelocity * dt;
      if (this.position.y <= 0) {
        this.position.y = 0;
        this.verticalVelocity = 0;
        this.grounded = true;
        this._playOnce('jump_land', { timeScale: 1.6, fade: 0.1, lock: false });
        this.onEvent('landed');
      }
    }

    // ---------------------------------------------------------------- shooting
    const wantsShoot = input.mouse.left;
    if (wantsShoot) {
      if (this.ammo[this.weapon] <= 0 && this.reloading <= 0) this._reload();
      else if (this.fireCooldown <= 0 && this.reloading <= 0 && this.posture !== POSTURE_PRONE) this._shoot();
      if (this.ammo[this.weapon] > 0 && this.reloading <= 0 && this.posture !== POSTURE_PRONE) {
        this.firingPoseTimer = 0.35;
      }
    }
    if (input.wasPressed('KeyR')) this._reload();

    // ------------------------------------------------------------------- hits
    if (input.wasPressed('KeyH')) this._playOnce('hit_light', { timeScale: 1.7, fade: 0.08, lock: false });
    if (input.wasPressed('KeyJ')) this._playOnce('hit_heavy', { timeScale: 1.4, fade: 0.08, lock: false });
    if (input.wasPressed('KeyK')) {
      this.dead = true;
      this.deathClip = Math.random() > 0.5 ? 'death_front' : 'death_back';
      this.velocity.set(0, 0, 0);
      this.character.play(this.deathClip, { fade: 0.12, restart: true });
      this.onEvent('died');
    }

    this._updateAnimation(moving);
    this._applyTransform();
  }

  _reload() {
    if (this.reloading > 0) return;
    if (this.ammo[this.weapon] === WEAPONS[this.weapon].magSize) return;
    const clipId = this.posture === POSTURE_PRONE ? 'reload_prone'
      : this.posture === POSTURE_CROUCH ? 'reload_crouch' : 'reload_stand';
    const action = this.character.actions.get(clipId);
    const duration = action ? action.getClip().duration : 1.5;
    const timeScale = Math.max(1, duration / 1.6);
    this.reloading = duration / timeScale;
    this._playOnce(clipId, { timeScale, fade: 0.15, lock: true });
    this.onEvent('reloading', { clipId });
  }

  _shoot() {
    const def = WEAPONS[this.weapon];
    this.fireCooldown = def.fireInterval;
    this.ammo[this.weapon] = Math.max(0, this.ammo[this.weapon] - 1);

    const muzzle = this.character.getMuzzleWorld(new THREE.Vector3());
    const yaw = this.yaw + (this.aiming ? 0 : this.recoilPitch * 0.2);
    const pitch = this.cameraPitch;
    const dir = new THREE.Vector3(
      Math.sin(yaw) * Math.cos(pitch),
      -Math.sin(pitch),
      Math.cos(yaw) * Math.cos(pitch),
    ).normalize();
    const spread = 0.006 + this.speed * 0.0035;
    dir.x += (Math.random() - 0.5) * spread * 2;
    dir.y += (Math.random() - 0.5) * spread * 2;
    dir.normalize();

    if (this.fx) this.fx.fire(muzzle, dir);
    this.recoilPitch += this.weapon === 'rifle' ? 0.028 : 0.02;
    this.onEvent('shot', { weapon: this.weapon, ammo: this.ammo[this.weapon], recoil: this.recoilPitch });
  }

  _updateAnimation(moving) {
    const ch = this.character;
    const moveLocal = this._localMoveDirection();

    if (this.oneShot) {
      ch.play(this.oneShot.id, { fade: this.oneShot.fade, timeScale: this.oneShot.timeScale });
      return;
    }
    if (!this.grounded) {
      ch.play('jump_air', { fade: 0.18 });
      return;
    }

    const firingPose = this.firingPoseTimer > 0;

    if (this.posture === POSTURE_PRONE) {
      if (this.speed < 0.12) ch.play('prone_idle', { fade: 0.25 });
      else if (moveLocal.y < -0.35) ch.play('crawl_back', { fade: 0.2, timeScale: scaleFor(this.speed, 0.8) });
      else ch.play('crawl_fwd', { fade: 0.2, timeScale: scaleFor(this.speed, 0.51) });
      return;
    }

    if (this.posture === POSTURE_CROUCH) {
      if (this.aiming) {
        if (this.speed < 0.12) { ch.play('crouch_aim_idle', { fade: 0.2 }); return; }
        const clip = pickDirectional('crouch', moveLocal);
        ch.play(clip, { fade: 0.2, timeScale: scaleFor(this.speed, crouchNaturalSpeed(clip)) });
        return;
      }
      if (this.speed < 0.12) { ch.play('crouch_idle', { fade: 0.25 }); return; }
      {
        const clip = pickDirectional('crouch', moveLocal);
        ch.play(clip, { fade: 0.2, timeScale: scaleFor(this.speed, crouchNaturalSpeed(clip)) });
      }
      return;
    }

    // standing
    if (this.aiming) {
      if (this.weapon === 'rifle') {
        if (firingPose) { ch.play('rifle_fire', { fade: 0.12, timeScale: 2.4 }); return; }
        if (this.speed < 0.12) ch.play('rifle_aim_idle', { fade: 0.2 });
        else if (this.speed > 2.6) ch.play('rifle_aim_run', { fade: 0.2, timeScale: scaleFor(this.speed, 3.15) });
        else ch.play('rifle_aim_walk', { fade: 0.2, timeScale: scaleFor(this.speed, 1.46) });
      } else {
        if (firingPose) { ch.play('pistol_aim_fire', { fade: 0.12, timeScale: 2.4 }); return; }
        if (this.speed < 0.12) ch.play('pistol_aim_idle', { fade: 0.2 });
        else {
          const clip = pickPistolDirectional(moveLocal);
          ch.play(clip, { fade: 0.2, timeScale: scaleFor(this.speed, clip === 'pistol_aim_left' ? 2.26 : 1.47) });
        }
      }
      return;
    }

    if (firingPose) {
      ch.play(this.weapon === 'rifle' ? 'rifle_fire' : 'pistol_aim_fire', { fade: 0.12, timeScale: 2.4 });
      return;
    }
    if (this.speed < 0.12) { ch.play('idle', { fade: 0.25 }); return; }
    if (this.sprinting && moving && this.speed > 4.4 && moveLocal.y > 0.38) {
      ch.play('sprint_fwd', { fade: 0.22, timeScale: scaleFor(this.speed, 6.12) });
      return;
    }
    if (this.speed > 2.6) {
      const clip = pickDirectional('run', moveLocal);
      ch.play(clip, { fade: 0.22, timeScale: scaleFor(this.speed, STAND_SPEEDS[clip]) });
      return;
    }
    {
      const clip = pickDirectional('walk', moveLocal);
      ch.play(clip, { fade: 0.22, timeScale: scaleFor(this.speed, STAND_SPEEDS[clip]) });
    }
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
      reloading: this.reloading > 0,
      clip: this.character.currentId || '-',
      speed: this.speed,
      dead: this.dead,
      grounded: this.grounded,
    };
  }
}

function scaleFor(actual, natural) {
  if (!natural) return 1;
  return THREE.MathUtils.clamp(actual / natural, 0.55, 1.9);
}

/** Ground speed baked into each standing locomotion clip (m/s). */
const STAND_SPEEDS = {
  walk_fwd: 1.67, walk_back: 1.20, walk_left: 1.94, walk_right: 1.38,
  run_fwd: 4.82, run_back: 3.22, run_left: 3.52, run_right: 3.96,
};

function crouchNaturalSpeed(clipId) {
  switch (clipId) {
    case 'crouch_back': return 1.28;
    case 'crouch_left': return 1.42;
    case 'crouch_right': return 1.12;
    default: return 2.05;
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
