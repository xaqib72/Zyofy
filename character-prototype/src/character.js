import * as THREE from 'three';
import { prepareRetarget, bakeClip, findBone } from './retarget.js';
import { createWeapon, alignWeaponToHand } from './weapons.js';
import { BONE_NAMES, TUNING } from './config.js';

/**
 * One controllable character: mesh + skeleton + retargeted animation set +
 * placeholder weapons mounted on the right hand.
 */
export class Character {
  constructor(def, gltf) {
    this.def = def;
    this.sourceGltf = gltf;
    this.group = new THREE.Group();
    this.group.name = `character_${def.id}`;
    // Orientation wrapper: normalises rigs that are authored facing -Z so that
    // "character forward" is always +Z for the controller, the camera and the
    // weapon mounting. Retargeting and the animation mixer both run on it.
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
    this.weapons = {};
    this.activeWeapon = 'rifle';
  }

  /** Pass 1 – capture bind poses while the source rigs are still in their bind pose. */
  prepareRetargeting(sourceClips) {
    for (const [id, src] of sourceClips) {
      this._retargetPrep.set(id, prepareRetarget(src.gltf, this.root, BONE_NAMES.hips));
    }
  }

  /** Pass 2 – bake retargeted clips and create the mixer + actions. */
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
      // LoopOnce with Infinity repetitions would replay a one-shot forever,
      // which is what made reload/hit/get-up clips visibly restart.
      action.setLoop(src.def.loop ? THREE.LoopRepeat : THREE.LoopOnce, src.def.loop ? Infinity : 1);
      this.actions.set(id, action);
      this.clipDefs.set(id, src.def);
      done += 1;
      if (onStep) onStep(done, sourceClips.size);
    }
    this._retargetPrep.clear();
  }

  /**
   * Mount both placeholder weapons. Runs while the rig is posed with each
   * weapon's aim idle, so the barrels line up with the character's forward axis.
   */
  setupWeapons(scene) {
    const poseSample = (clipId) => {
      const action = this.actions.get(clipId);
      if (!action) return false;
      this.mixer.stopAllAction();
      action.reset().play();
      this.mixer.setTime(0.05);
      this.root.updateMatrixWorld(true);
      return true;
    };

    for (const kind of ['rifle', 'pistol']) {
      const built = createWeapon(kind);
      const aimClip = kind === 'rifle' ? 'rifle_aim_idle' : 'pistol_aim_idle';
      poseSample(aimClip);
      alignWeaponToHand(built.group, this.bones.rightHand, { pitch: kind === 'rifle' ? -0.02 : 0.02 });
      built.group.name = `${kind}_${this.def.id}`;
      built.group.visible = false;
      this.bones.rightHand.add(built.group);
      this.weapons[kind] = built;
    }

    this.mixer.stopAllAction();
    this.root.updateMatrixWorld(true);
    this.activeWeapon = 'rifle';
    this.weapons.rifle.group.visible = true;
    void scene;
  }

  setWeapon(kind) {
    if (!this.weapons[kind]) return;
    this.activeWeapon = kind;
    for (const [k, w] of Object.entries(this.weapons)) w.group.visible = k === kind;
  }

  /** Follows the weld: the weapon rides the hand bone, so just read its world transform. */
  getMuzzleWorld(out = new THREE.Vector3()) {
    const w = this.weapons[this.activeWeapon];
    if (!w) return out.set(0, 0, 0);
    return w.muzzle.getWorldPosition(out);
  }

  /**
   * Makes `id` the one audible action.
   *
   * Blending is managed explicitly instead of with `crossFadeFrom`: a fade that
   * is interrupted (a clip replaced while it is still fading in or out) leaves
   * three.js' internal weight interpolant frozen at a fractional value, and the
   * action then keeps contributing a slice of its pose forever - which is
   * exactly how two animations end up playing on the same skeleton. Here every
   * action's weight is written directly and the outgoing one is `stop()`ped as
   * soon as the blend completes, so no action can ever leak.
   */
  play(id, { fade = TUNING.crossFade, timeScale = 1, restart = false } = {}) {
    const next = this.actions.get(id);
    if (!next) return;
    if (this.current === next && !restart) {
      next.timeScale = timeScale;
      return;
    }

    // Cancel any in-flight blend: the action that was fading out is dropped.
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

    // Safety net: nothing except the current action and the two blend partners
    // may be running. This is the invariant that keeps duplicate animations
    // from ever appearing on screen.
    const blend = this._blend;
    for (const action of this.actions.values()) {
      if (action === this.current) continue;
      if (blend && (action === blend.from || action === blend.to)) continue;
      if (action.isRunning()) action.stop();
    }
  }
}
