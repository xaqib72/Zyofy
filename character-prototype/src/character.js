import * as THREE from 'three';
import { prepareRetarget, bakeClip, findBone } from './retarget.js';
import { buildWeapon, alignWeaponToHand, buildViewArms } from './weapons.js';
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
    this.viewModels = {};
    this.viewModelRoot = null;
    this.viewModelId = null;

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
        hipYOffset: src.def.hipYOffset || 0,
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
      alignWeaponToHand(built.group, this.bones.rightHand, { pitch: def.firePose === 'long' ? -0.02 : 0.02 });
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

  setWeapon(id) {
    if (!this.weapons[id]) return;
    this.activeWeapon = id;
    for (const [key, weapon] of Object.entries(this.weapons)) {
      weapon.group.visible = key === id;
    }
    this._syncViewModels();
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
   * A first-person weapon needs to be camera-relative, otherwise it is seen from
   * an eye that sits *behind* the hand and the barrel sweeps across the screen.
   * The character's hand-welded weapon stays for third person (and for the world
   * position of the muzzle); in first person this viewmodel is shown instead,
   * parented to the camera at a fixed offset, exactly like a normal FPS.
   */
  attachViewModel(camera) {
    this.viewModelRoot = new THREE.Group();
    this.viewModelRoot.name = `viewmodel_${this.def.id}`;
    camera.add(this.viewModelRoot);
    if (camera.parent === null) this._orphanCamera = camera;
    this._syncViewModels();
  }

  _syncViewModels() {
    if (!this.viewModelRoot) return;
    const def = WEAPONS[this.activeWeapon];
    let vm = this.viewModels[def.id];
    if (!vm) {
      vm = buildWeapon(def, { viewmodel: true });
      vm.group.visible = false;
      // arms belong to the weapon so the hands sit exactly on its grips
      const foreZ = Math.max(0.18, vm.length * 0.42);
      vm.group.add(buildViewArms(def.firePose === 'long', foreZ));
      this.viewModelRoot.add(vm.group);
      for (const part of Object.values(vm.parts)) {
        part.userData.basePosition = part.position.clone();
        part.userData.baseRotation = part.rotation.clone();
      }
      this.viewModels[def.id] = vm;
    }
    for (const [key, model] of Object.entries(this.viewModels)) {
      model.group.visible = key === def.id;
    }
    this.viewModelId = def.id;
  }

  /** Places the viewmodel for the current camera mode, recoil and reload. */
  updateViewModel(dt, controller, mode) {
    if (!this.viewModelRoot) return;
    const handWeapon = this.weapons[this.activeWeapon];
    if (handWeapon) handWeapon.group.visible = mode !== 'fps';
    this.viewModelRoot.visible = mode === 'fps';
    if (mode !== 'fps') return;

    const vm = this.viewModels[this.viewModelId];
    if (!vm) return;

    const recoil = controller ? Math.min(0.06, (controller.recoilPitch || 0) * 1.4) : 0;
    const isLong = this.activeWeaponDef ? this.activeWeaponDef.firePose === 'long' : true;
    const base = isLong
      ? { x: 0.16, y: -0.14, z: -0.38 }
      : { x: 0.15, y: -0.13, z: -0.34 };

    // reload: dip and tilt the weapon out of the line of sight
    let reloadK = 0;
    if (controller && controller.reloading > 0 && controller.reloadDuration > 0) {
      const t = 1 - controller.reloading / controller.reloadDuration;
      reloadK = Math.sin(Math.PI * THREE.MathUtils.clamp(t, 0, 1));
    }

    vm.group.scale.setScalar(0.95);
    vm.group.position.set(
      base.x - reloadK * 0.04,
      base.y - reloadK * 0.12 + recoil * 0.25,
      base.z + recoil * 0.6,
    );
    // The weapon models are authored with the barrel along +Z. The camera looks
    // down its own -Z, so a half-turn about Y points the barrel downrange.
    vm.group.rotation.set(
      -0.02 + recoil * 1.6 + reloadK * 0.5,
      Math.PI - 0.06 - reloadK * 0.35,
      reloadK * 0.4,
    );
    void dt;

    // the moving parts of the viewmodel mirror the hand weapon
    for (const [name, part] of Object.entries(vm.parts)) {
      const source = handWeapon && handWeapon.parts[name];
      if (!source || !part.userData.basePosition) continue;
      part.position.copy(part.userData.basePosition);
      part.rotation.copy(part.userData.baseRotation);
      if (handWeaponActivePart(source, handWeapon)) {
        part.position.y += source.position.y - (source.userData.basePosition?.y ?? source.position.y);
        part.position.z += source.position.z - (source.userData.basePosition?.z ?? source.position.z);
        part.rotation.z += source.rotation.z - (source.userData.baseRotation?.z ?? source.rotation.z);
      }
    }
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

    const blend = this._blend;
    for (const action of this.actions.values()) {
      if (action === this.current) continue;
      if (blend && (action === blend.from || action === blend.to)) continue;
      if (action.isRunning()) action.stop();
    }
  }
}

/** true when the hand weapon's part has moved away from its rest transform */
function handWeaponActivePart(part) {
  const baseP = part.userData.basePosition;
  const baseR = part.userData.baseRotation;
  if (!baseP || !baseR) return false;
  return part.position.distanceTo(baseP) > 1e-4
    || Math.abs(part.rotation.z - baseR.z) > 1e-4
    || Math.abs(part.rotation.x - baseR.x) > 1e-4;
}
