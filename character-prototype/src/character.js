import * as THREE from 'three';
import { prepareRetarget, bakeClip, findBone } from './retarget.js';
import { buildWeapon, alignWeaponToHand } from './weapons.js';
import { WEAPON_LIST, WEAPONS } from './weaponCatalog.js';
import { BONE_NAMES, TUNING } from './config.js';

/**
 * One controllable character: mesh + skeleton + retargeted animation set + the
 * full weapon collection mounted on the right hand.
 */
export class Character {
  constructor(def, gltf) {
    this.def = def;
    this.sourceGltf = gltf;
    this.group = new THREE.Group();
    this.group.name = `character_${def.id}`;
    this.root = new THREE.Object3D();
    this.root.name = `rig_${def.id}`;
    this.root.rotation.y = def.modelYaw || 0;
    this.root.position.y = def.heightOffset || 0;
    this.root.add(gltf.scene);
    this.group.add(this.root);

    this.root.traverse((o) => {
      if (o.isMesh || o.isSkinnedMesh) {
        o.castShadow = true;
        o.receiveShadow = true;
        o.frustumCulled = false;
      }
    });

    this.bones = {
      hips: findBone(this.root, BONE_NAMES.hips),
      spine2: findBone(this.root, BONE_NAMES.spine2),
      head: findBone(this.root, BONE_NAMES.head),
      rightHand: findBone(this.root, BONE_NAMES.rightHand),
      leftHand: findBone(this.root, BONE_NAMES.leftHand),
      leftArm: findBone(this.root, BONE_NAMES.leftArm),
      leftForeArm: findBone(this.root, BONE_NAMES.leftForeArm),
      rightArm: findBone(this.root, BONE_NAMES.rightArm),
      rightForeArm: findBone(this.root, BONE_NAMES.rightForeArm),
    };

    this.mixer = null;
    this.actions = new Map();
    this.clipDefs = new Map();
    this.current = null;
    this.currentId = null;
    this._retargetPrep = new Map();
    this._blend = null;

    this.weapons = {};
    this.activeWeapon = 'm4a1';

    /** camera-relative viewmodel used in first person (one per weapon) */

    // weapon mechanics state
    this._slideTimer = 0;
    this._cycle = null;
    this._reload = null;
  }

  /* ------------------------------------------------------------- retargeting */

  prepareRetargeting(sourceClips) {
    for (const [id, src] of sourceClips) {
      this._retargetPrep.set(id, prepareRetarget(src.gltf, this.root, BONE_NAMES.hips));
    }
  }

  bakeMotion(sourceClips, onStep) {
    this.mixer = new THREE.AnimationMixer(this.root);
    let done = 0;
    for (const [id, src] of sourceClips) {
      const prep = this._retargetPrep.get(id);
      const baked = bakeClip(src.clip, prep, {
        fps: TUNING.animationFps,
        inPlace: true,
        // clips that deliberately turn the body (melee swings, deaths) keep their
        // hips yaw; everything else inherits the bind-pose facing
        inPlaceTurn: !src.def.keepTurn,
        hipYOffset: src.def.hipYOffset || 0,
        loop: !!src.def.loop,
        trimStart: src.def.trimStart || 0,
        trimEnd: src.def.trimEnd || 0,
      });
      baked.name = id;
      const action = this.mixer.clipAction(baked);
      action.clampWhenFinished = !src.def.loop;
      action.setLoop(src.def.loop ? THREE.LoopRepeat : THREE.LoopOnce, src.def.loop ? Infinity : 1);
      this.actions.set(id, action);
      this.clipDefs.set(id, src.def);
      done += 1;
      if (onStep) onStep(done, sourceClips.size);
    }
    this._retargetPrep.clear();
  }

  /* ----------------------------------------------------------------- weapons */

  /**
   * Builds every weapon in the catalogue and mounts it on the right hand, in the
   * aim pose that weapon family uses, so its barrel lines up with the character's
   * forward axis without per-weapon hand tuning.
   */
  setupWeapons(scene) {
    const poseSample = (clipId) => {
      const action = this.actions.get(clipId);
      if (!action) return;
      this.mixer.stopAllAction();
      action.reset().play();
      this.mixer.setTime(0.05);
      this.root.updateMatrixWorld(true);
    };

    for (const def of WEAPON_LIST) {
      const pose = def.firePose === 'long' ? 'rifle_aim_idle'
        : def.firePose === 'melee' ? 'knife_idle' : 'pistol_aim_idle';
      poseSample(pose);
      const built = buildWeapon(def);
      // Aim the barrel along the *character's* forward axis (not world +Z: the
      // male GLB is authored facing -Z) and let each weapon's own muzzle define
      // its barrel axis. `attach` in the catalogue carries that weapon's trim.
      const forward = new THREE.Vector3(0, 0, 1).applyQuaternion(this.group.quaternion).normalize();
      const attach = def.attach || {};
      alignWeaponToHand(built.group, this.bones.rightHand, {
        muzzle: built.muzzle,
        targetDir: forward,
        pitch: attach.pitch ?? (def.firePose === 'long' ? -0.03 : 0.01),
        yaw: attach.yaw ?? 0,
        roll: attach.roll ?? 0,
      });
      built.attach = { ...attach };
      built.group.visible = false;
      this.bones.rightHand.add(built.group);
      this.weapons[def.id] = built;

      // remember the rest transform of every moving part
      for (const part of Object.values(built.parts)) {
        part.userData.basePosition = part.position.clone();
        part.userData.baseRotation = part.rotation.clone();
      }
    }

    this.mixer.stopAllAction();
    this.root.updateMatrixWorld(true);
    this.setWeapon(this.activeWeapon);
    void scene;
  }

  /** Holstered state: keep the weapon attached but out of sight. */
  setArmed(armed) {
    this.armed = armed;
    for (const [key, weapon] of Object.entries(this.weapons)) {
      weapon.group.visible = armed && key === this.activeWeapon;
    }
  }

  setWeapon(id) {
    if (!this.weapons[id]) return;
    this.activeWeapon = id;
    const armed = this.armed !== false;
    for (const [key, weapon] of Object.entries(this.weapons)) {
      weapon.group.visible = armed && key === id;
    }
    this._slideTimer = 0;
    this._cycle = null;
    this._reload = null;
    this._resetParts();
  }

  get activeWeaponDef() {
    return WEAPONS[this.activeWeapon];
  }

  getMuzzleWorld(out = new THREE.Vector3()) {
    const weapon = this.weapons[this.activeWeapon];
    if (!weapon) return out.set(0, 0, 0);
    return weapon.muzzle.getWorldPosition(out);
  }

  /* -------------------------------------------------------- weapon mechanics */

  /** Kick the slide/slide-equivalent back for one shot. */
  cycleWeaponPart(kind, duration) {
    this._cycle = { kind, elapsed: 0, duration };
    this._slideTimer = 0.09;
  }

  beginReloadMechanics(style, duration) {
    this._reload = { style, elapsed: 0, duration };
  }

  endReloadMechanics() {
    this._reload = null;
    this._resetParts();
  }

  _resetParts() {
    const weapon = this.weapons[this.activeWeapon];
    if (!weapon) return;
    for (const part of Object.values(weapon.parts)) {
      if (!part.userData.basePosition) continue;
      part.position.copy(part.userData.basePosition);
      part.rotation.copy(part.userData.baseRotation);
    }
  }

  /**
   * Animates the mechanical parts of the *equipped* weapon: the slide kicks on
   * every shot, bolt handles lift and cycle, pump forends rack, revolver
   * cylinders swing and spin, magazines drop out and seat again. This is what
   * makes each weapon's handling read differently even though the character
   * animation clips are shared per family.
   */
  updateWeaponMechanics(dt, controller) {
    const weapon = this.weapons[this.activeWeapon];
    if (!weapon) return;
    const parts = weapon.parts;

    this._slideTimer = Math.max(0, this._slideTimer - dt);

    if (this._cycle) {
      this._cycle.elapsed += dt;
      if (this._cycle.elapsed >= this._cycle.duration) this._cycle = null;
    }

    if (this._reload) {
      this._reload.elapsed += dt;
      if (this._reload.elapsed >= this._reload.duration) this._reload = null;
    }

    const set = (part, fn) => {
      if (!part || !part.userData.basePosition) return;
      part.position.copy(part.userData.basePosition);
      part.rotation.copy(part.userData.baseRotation);
      fn(part);
    };

    // slide / bolt recoil
    const kick = this._slideTimer > 0 ? this._slideTimer / 0.09 : 0;
    set(parts.slide, (p) => { p.position.z = p.userData.basePosition.z - 0.028 * kick; });

    // bolt action + pump action cycling
    const cycleK = this._cycle ? Math.sin(Math.PI * Math.min(1, this._cycle.elapsed / this._cycle.duration)) : 0;
    if (this._cycle && this._cycle.kind === 'bolt') {
      set(parts.bolt, (p) => {
        p.rotation.z = p.userData.baseRotation.z + 1.15 * cycleK;
        p.position.z = p.userData.basePosition.z - 0.05 * cycleK;
      });
    } else if (this._cycle && this._cycle.kind === 'pump') {
      set(parts.pump, (p) => { p.position.z = p.userData.basePosition.z - 0.075 * cycleK; });
    } else {
      set(parts.bolt, () => {});
      set(parts.pump, () => {});
    }

    // reload mechanics
    if (this._reload) {
      const k = Math.min(1, this._reload.elapsed / this._reload.duration);
      const bell = Math.sin(Math.PI * k);
      switch (this._reload.style) {
        case 'cylinder':
          // swing out, spin, snap shut
          set(parts.cylinder, (p) => {
            p.rotation.x = p.userData.baseRotation.x + bell * 0.55;
            p.rotation.z = p.userData.baseRotation.z + Math.PI * 2 * Math.min(1, k * 1.4);
            p.position.x = p.userData.basePosition.x + bell * 0.02;
          });
          break;
        case 'mag':
          set(parts.mag, (p) => {
            p.position.y = p.userData.basePosition.y - bell * 0.2;
            p.position.z = p.userData.basePosition.z + (k < 0.5 ? bell * 0.04 : 0);
          });
          break;
        case 'pump':
          set(parts.pump, (p) => { p.position.z = p.userData.basePosition.z - bell * 0.08; });
          break;
        case 'bolt':
          set(parts.bolt, (p) => {
            p.rotation.z = p.userData.baseRotation.z + bell * 1.3;
            p.position.z = p.userData.basePosition.z - bell * 0.055;
          });
          break;
        default:
          break;
      }
    } else if (this._slideTimer <= 0) {
      set(parts.cylinder, () => {});
      set(parts.mag, () => {});
    }

    // cylinder indexes one chamber per shot on revolvers
    if (parts.cylinder && this._reload === null && this._slideTimer > 0) {
      set(parts.cylinder, (p) => { p.rotation.z = p.userData.baseRotation.z + (Math.PI / 3) * kick; });
    }

    void controller;
  }

  /* --------------------------------------------------------------- animation */

  /**
   * Makes `id` the one audible action. Blending is explicit: a fade that gets
   * replaced mid-way would otherwise leave three.js' weight interpolant frozen
   * at a fraction, and the stale action keeps a slice of its pose forever.
   */
  play(id, { fade = TUNING.crossFade, timeScale = 1, restart = false } = {}) {
    const next = this.actions.get(id);
    if (!next) return;
    if (this.current === next && !restart) {
      next.timeScale = timeScale;
      return;
    }

    if (this._blend) {
      this._blend.from.stop();
      this._blend.from.setEffectiveWeight(1);
      this._blend = null;
    }

    const prev = this.current;
    next.reset();
    next.timeScale = timeScale;
    next.enabled = true;
    next.setEffectiveWeight(prev && prev !== next ? 0 : 1);
    next.play();

    if (prev && prev !== next && fade > 0.001) {
      prev.setEffectiveWeight(1);
      this._blend = { from: prev, to: next, elapsed: 0, duration: fade };
    } else if (prev && prev !== next) {
      prev.stop();
      prev.setEffectiveWeight(1);
      next.setEffectiveWeight(1);
    }

    this.current = next;
    this.currentId = id;
  }

  /* -------------------------------------------------------------- viewmodel */

  /**
   * First person is handled by a shared, downloaded, rigged viewmodel (see
   * fpsRig.js) rather than by anything attached to this character. All this
   * needs to do is keep the hand-welded third-person weapon out of the way while
   * the first-person rig is on screen, and hand the active weapon over to it.
   */
  updateHandWeaponVisibility(mode) {
    const handWeapon = this.weapons[this.activeWeapon];
    if (handWeapon) handWeapon.group.visible = mode !== 'fps';
  }

  update(dt) {
    if (!this.mixer) return;

    if (this._blend) {
      const b = this._blend;
      b.elapsed += dt;
      const k = Math.min(1, b.elapsed / b.duration);
      b.from.setEffectiveWeight(1 - k);
      b.to.setEffectiveWeight(k);
      if (k >= 1) {
        b.from.stop();
        b.from.setEffectiveWeight(1);
        this._blend = null;
      }
    }

    this.mixer.update(dt);
    this._updateAimSolve(dt);
    this._updateSupportHand();

    const blend = this._blend;
    for (const action of this.actions.values()) {
      if (action === this.current) continue;
      if (blend && (action === blend.from || action === blend.to)) continue;
      if (action.isRunning()) action.stop();
    }
  }

  /**
   * Aim solve.
   *
   * While the player aims, the barrel has to point where the camera looks, not
   * where the posed hand happens to point it. The weapon is rotated about its own
   * grip, on top of the pose, by the shortest arc that takes its barrel onto
   * `aimTarget`. The arc is clamped (a wrist cannot bend past ~18 deg) and eased,
   * so the correction reads as the arm taking up the angle, and it is released
   * smoothly when the player stops aiming.
   */
  _updateAimSolve(dt) {
    const weapon = this.weapons[this.activeWeapon];
    if (!weapon || !this.bones.rightHand) return;
    if (!weapon.baseQuaternion) weapon.baseQuaternion = weapon.group.quaternion.clone();

    const hand = this.bones.rightHand;
    hand.updateWorldMatrix(true, false);
    const handQ = hand.getWorldQuaternion(new THREE.Quaternion());

    if (!this._aimCorrection) this._aimCorrection = new THREE.Quaternion();
    const wanted = this._aimWanted || (this._aimWanted = new THREE.Quaternion());
    wanted.identity();

    const target = this.aimTarget;
    if (target && target.lengthSq() > 1e-6) {
      const barrelLocal = weapon.muzzle.position.clone();
      if (barrelLocal.lengthSq() > 1e-8) {
        barrelLocal.normalize();
        const baseWorldQ = handQ.clone().multiply(weapon.baseQuaternion);
        const barrelWorld = barrelLocal.clone().applyQuaternion(baseWorldQ).normalize();
        wanted.setFromUnitVectors(barrelWorld, target.clone().normalize());
        // clamp the arc: aim assist, not a turret
        const angle = 2 * Math.acos(THREE.MathUtils.clamp(wanted.w, -1, 1));
        const MAX = 0.32;
        if (angle > MAX) {
          const t = (angle - MAX) / 2;
          wanted.set(
            wanted.x * Math.sin(t) / Math.sin(angle / 2),
            wanted.y * Math.sin(t) / Math.sin(angle / 2),
            wanted.z * Math.sin(t) / Math.sin(angle / 2),
            Math.cos(t),
          ).normalize();
        }
      }
    }

    const k = 1 - Math.exp(-dt * 14);
    this._aimCorrection.slerp(wanted, k);
    if (Math.abs(this._aimCorrection.w) > 0.99999 && Math.abs(1 - Math.abs(this._aimCorrection.w)) < 1e-5) this._aimCorrection.identity();
    // the correction is a world-space arc: conjugate it into the hand's space,
    // then re-apply the pose-local base rotation
    _q1.copy(handQ).invert().multiply(this._aimCorrection).multiply(handQ).multiply(weapon.baseQuaternion);
    weapon.group.quaternion.copy(_q1);
  }

  /**
   * Puts the support (left) hand on the weapon with a two-bone IK solve.
   *
   * The retargeted clips pose the left arm for *their* own weapon, so on these
   * models the support hand sat 20-45 cm away from the handguard. The hand is
   * welded to a point on the weapon's own bore line, under the barrel, and the
   * upper arm and forearm are rotated to reach it. Because the target is read off
   * the weapon every frame, it stays on the grip through recoil, reload and aim.
   */
  _updateSupportHand() {
    const weapon = this.weapons[this.activeWeapon];
    const { leftArm, leftForeArm, leftHand } = this.bones;
    if (!weapon || !leftArm || !leftForeArm || !leftHand) return;
    const pose = weapon.def.firePose;
    if (pose === 'melee' || this.armed === false) return;
    // while prone the weapon rests on the ground and the clip owns both arms; an
    // IK solve there drags the support hand through the floor
    if (this.supportHandEnabled === false) return;

    const barrel = weapon.muzzle.position.clone();
    const length = barrel.length();
    if (length < 1e-4) return;
    barrel.normalize();
    // long guns are held on the handguard, pistols two-handed at the grip
    const fraction = pose === 'long' ? 0.52 : 0.24;
    const local = barrel.multiplyScalar(length * fraction);
    local.y -= length * 0.03;            // palm wraps under the bore
    const target = weapon.group.localToWorld(local);

    this.root.updateMatrixWorld(true);
    _ikA.copy(leftArm.getWorldPosition(new THREE.Vector3()));
    _ikB.copy(leftForeArm.getWorldPosition(new THREE.Vector3()));
    _ikC.copy(leftHand.getWorldPosition(new THREE.Vector3()));

    const l1 = _ikA.distanceTo(_ikB);
    const l2 = _ikB.distanceTo(_ikC);
    if (l1 < 1e-4 || l2 < 1e-4) return;

    _ikDir.copy(target).sub(_ikA);
    let dist = _ikDir.length();
    if (dist < 1e-4) return;
    _ikDir.multiplyScalar(1 / dist);
    dist = THREE.MathUtils.clamp(dist, Math.abs(l1 - l2) + 1e-3, l1 + l2 - 1e-3);

    // the elbow stays on the side of the shoulder-wrist line that the pose put it
    // on, so it bends like an elbow instead of flipping through the body
    _ikPole.copy(_ikB).sub(_ikA);
    _ikPole.addScaledVector(_ikDir, -_ikPole.dot(_ikDir));
    if (_ikPole.lengthSq() < 1e-8) {
      _ikPole.set(0, -1, 0).addScaledVector(_ikDir, _ikDir.y);
    }
    _ikPole.normalize();

    const cosA = THREE.MathUtils.clamp((l1 * l1 + dist * dist - l2 * l2) / (2 * l1 * dist), -1, 1);
    const sinA = Math.sqrt(Math.max(0, 1 - cosA * cosA));
    const elbow = _ikA.clone()
      .addScaledVector(_ikDir, l1 * cosA)
      .addScaledVector(_ikPole, l1 * sinA);

    // 1. swing the upper arm so the elbow lands on the solved point
    _ikQ.setFromUnitVectors(_ikB.clone().sub(_ikA).normalize(), elbow.clone().sub(_ikA).normalize());
    this._rotateBoneWorld(leftArm, _ikQ);
    leftForeArm.updateWorldMatrix(true, false);
    _ikB.copy(elbow);
    _ikC.copy(leftHand.getWorldPosition(new THREE.Vector3()));

    // 2. bend the elbow so the wrist reaches the weapon
    const wristDir = _ikC.clone().sub(_ikB);
    const wantDir = target.clone().sub(_ikB);
    if (wristDir.lengthSq() > 1e-8 && wantDir.lengthSq() > 1e-8) {
      _ikQ.setFromUnitVectors(wristDir.normalize(), wantDir.normalize());
      this._rotateBoneWorld(leftForeArm, _ikQ);
    }
  }

  /** Applies a world-space rotation delta to a bone, keeping its parent chain. */
  _rotateBoneWorld(bone, worldDelta) {
    bone.updateWorldMatrix(true, false);
    const parentQ = bone.parent
      ? bone.parent.getWorldQuaternion(new THREE.Quaternion())
      : new THREE.Quaternion();
    const worldQ = bone.getWorldQuaternion(new THREE.Quaternion());
    const nextWorld = worldDelta.clone().multiply(worldQ);
    bone.quaternion.copy(parentQ.invert().multiply(nextWorld));
    bone.updateMatrixWorld(true);
  }

  /** World direction the barrel should take; `null` releases the aim solve. */
  setAimTarget(dir) {
    this.aimTarget = dir ? (this.aimTarget || new THREE.Vector3()).copy(dir) : null;
  }
}

const _q1 = new THREE.Quaternion();
const _ikA = new THREE.Vector3();
const _ikB = new THREE.Vector3();
const _ikC = new THREE.Vector3();
const _ikDir = new THREE.Vector3();
const _ikPole = new THREE.Vector3();
const _ikQ = new THREE.Quaternion();

/** true when the hand weapon's part has moved away from its rest transform */
function handWeaponActivePart(part) {
  const baseP = part.userData.basePosition;
  const baseR = part.userData.baseRotation;
  if (!baseP || !baseR) return false;
  return part.position.distanceTo(baseP) > 1e-4
    || Math.abs(part.rotation.z - baseR.z) > 1e-4
    || Math.abs(part.rotation.x - baseR.x) > 1e-4;
}
