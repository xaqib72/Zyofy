import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { instantiateWeaponModel } from './weaponAssets.js';

/**
 * First-person viewmodel built from a downloaded, rigged asset.
 *
 * `public/assets/fps/ak47_hands.glb` is the "Ak47" first-person model by
 * kursat_sokmen (Sketchfab, CC BY 4.0): a skinned pair of gloved arms *holding an
 * AK-47*, with the animation clips `idle`, `fire` and `reload_empty` authored for
 * it. The gun is a separate skinned mesh driven by its own bones (`gun`,
 * `slider`, `magazine`, `trigger`, `gun_end`), which is what makes this usable as
 * a general viewmodel:
 *
 *   - equip the AK          -> its own gun mesh is shown, `fire`/`reload_empty` play
 *   - equip anything else   -> the gun mesh is hidden, the downloaded weapon model
 *                              is attached to the `gun` bone and scaled to it, so
 *                              the real animated hands keep holding the weapon and
 *                              the same real fire/reload clips still play
 *
 * Nothing here synthesises hand or arm geometry. The only custom code is the
 * scaling/placement that fits the asset to the camera.
 */

/**
 * World-space positions of a (possibly skinned) mesh, sampling every `stride`th
 * vertex. `Box3.setFromObject` reads bind-pose geometry for skinned meshes, which
 * is nowhere near where the posed gun actually is - this walks the real skin.
 */
function sampleWorldPoints(object, stride = 5, out = []) {
  const v = new THREE.Vector3();
  object.traverse((child) => {
    if (!child.isMesh) return;
    // SkinnedMesh.applyBoneTransform reads `skeleton.boneMatrices`, which only
    // the renderer refreshes. Refresh them explicitly, otherwise every
    // measurement below would be taken in the bind pose rather than the pose
    // the mixer just produced.
    if (child.isSkinnedMesh) child.skeleton.update();
    const pos = child.geometry.attributes.position;
    for (let i = 0; i < pos.count; i += stride) {
      v.fromBufferAttribute(pos, i);
      if (child.isSkinnedMesh) {
        child.applyBoneTransform(i, v);
      }
      v.applyMatrix4(child.matrixWorld);
      out.push(v.clone());
    }
  });
  return out;
}

function boundsOf(points) {
  const box = new THREE.Box3();
  for (const p of points) box.expandByPoint(p);
  return box;
}

export class FpsRig {
  constructor() {
    this.group = new THREE.Group();
    this.group.name = 'fps_rig';
    this.loaded = false;
    this.mixer = null;
    this.actions = {};
    this.current = null;
    this.weaponId = null;
    this.gunMesh = null;
    this.gunBone = null;
    this.muzzleBone = null;
    this.attached = null;
    this._onFire = 0;
    this.error = null;
    /** where the grip of the weapon should sit in camera space */
    this.gripOffset = new THREE.Vector3(0.19, -0.20, -0.88);
    /** overall viewmodel scale, so the arms do not crowd the screen */
    this.viewScale = 0.86;
    /** a few degrees of yaw/pitch so the receiver reads as a 3D object */
    this.viewYaw = 0.07;
    this.viewPitch = 0.03;
  }

  async load(url = 'assets/fps/ak47_hands.glb', { length = 0.88 } = {}) {
    try {
      const gltf = await new GLTFLoader().loadAsync(url);
      return this.adopt(gltf, { length });
    } catch (error) {
      this.error = String(error && error.message ? error.message : error);
      console.error('first-person rig failed to load', error);
      return this;
    }
  }

  /** Adopts an already-parsed GLTF (used by the offline render/verify scripts). */
  adopt(gltf, { length = 0.88 } = {}) {
    {
      this.scene = gltf.scene;
      this.clips = gltf.animations;
      this.group.add(this.scene);

      this.mixer = new THREE.AnimationMixer(this.scene);
      for (const clip of this.clips) {
        const action = this.mixer.clipAction(clip);
        action.loop = clip.name === 'idle' ? THREE.LoopRepeat : THREE.LoopOnce;
        action.clampWhenFinished = clip.name !== 'idle';
        this.actions[clip.name] = action;
      }

      this.scene.traverse((child) => {
        if (child.isSkinnedMesh && child.name === 'SMDImport') this.gunMesh = child;
        if (child.isMesh) {
          child.castShadow = false;
          child.receiveShadow = false;
          child.frustumCulled = false;
        }
      });
      this.gunBone = this.scene.getObjectByName('gun');
      this.muzzleBone = this.scene.getObjectByName('gun_end');

      // Play the idle pose so the arms are in their held-gun pose before we
      // measure anything; the bind pose (T-ish) is not the shape we want.
      this.play('idle', 0);
      this.mixer.update(0);
      this.scene.updateMatrixWorld(true);

      // Auto-scale: make the model's own AK exactly `length` metres long.
      const box = boundsOf(sampleWorldPoints(this.gunMesh || this.scene, 7));
      const size = box.getSize(new THREE.Vector3());
      const akLength = Math.max(size.z, size.x, size.y);
      this.unitScale = length / Math.max(1e-6, akLength);
      this.group.scale.setScalar(this.unitScale * this.viewScale);
      this.group.rotation.y = Math.PI; // model points +Z, the camera looks -Z
      this.group.updateMatrixWorld(true);

      this.gunRestLength = akLength * this.unitScale;

      // Align the *measured* barrel axis with the camera's forward axis instead
      // of guessing an angle: the authored model points its gun slightly down
      // and across, so the correction is computed from the mesh itself.
      this.alignBarrelToCamera();
      this.applyViewAngles();
      this.placeForCamera();
      this.loaded = true;
      return this;
    }
  }


  /**
   * Rotates the rig so the gun's real barrel axis points down the camera's -Z.
   *
   * The axis is measured from the gun mesh: the two end slabs of its bounding
   * box give the rear and muzzle centres, and the vector between them is the
   * bore line. A rotation basis is then built that maps that line onto -Z while
   * keeping the weapon upright, which also fixes any roll in the source model.
   */
  alignBarrelToCamera() {
    const gun = this.gunMesh || this.scene;
    this.group.quaternion.identity();
    this.group.position.set(0, 0, 0);
    this.group.updateMatrixWorld(true);

    // measure the bore line in the rig's own frame
    const points = sampleWorldPoints(gun, 1);
    const box = boundsOf(points);
    const size = box.getSize(new THREE.Vector3());
    const axis = size.x > size.y && size.x > size.z ? 'x' : size.y > size.z ? 'y' : 'z';
    const lo = box.min[axis];
    const hi = box.max[axis];
    const step = (hi - lo) * 0.12;
    const mean = (from, to) => {
      const c = new THREE.Vector3();
      let n = 0;
      for (const p of points) {
        if (p[axis] >= from && p[axis] <= to) { c.add(p); n += 1; }
      }
      return n ? c.multiplyScalar(1 / n) : null;
    };
    const rear = mean(lo, lo + step);
    const front = mean(hi - step, hi);
    if (!rear || !front) return;

    const bore = front.clone().sub(rear).normalize();

    // 1. point the bore along the camera's forward axis (-Z)
    const q = new THREE.Quaternion().setFromUnitVectors(bore, new THREE.Vector3(0, 0, -1));

    // 2. remove the roll that setFromUnitVectors leaves free: the weapon's own
    //    up vector is brought as close to +Y as it can be.
    const modelUp = new THREE.Vector3(0, 1, 0).applyQuaternion(q);
    const flatUp = new THREE.Vector3(modelUp.x, modelUp.y, 0);
    if (flatUp.lengthSq() > 1e-6) {
      flatUp.normalize();
      const roll = Math.atan2(flatUp.x, flatUp.y);
      q.premultiply(new THREE.Quaternion().setFromAxisAngle(new THREE.Vector3(0, 0, 1), roll));
    }

    this.group.quaternion.copy(q);
    this.group.updateMatrixWorld(true);

    // remember where the muzzle and the grip ended up, in rig space
    this.barrelDir = bore.clone().applyQuaternion(q).toArray().map((n) => Number(n.toFixed(3)));
    this.muzzleLocal = front.clone().applyQuaternion(q);
    this.rearLocal = rear.clone().applyQuaternion(q);
  }

  /** Small presentation angles on top of the barrel alignment. */
  applyViewAngles() {
    this.alignedQuaternion = this.group.quaternion.clone();
    const tilt = new THREE.Quaternion().setFromEuler(
      new THREE.Euler(this.viewPitch, this.viewYaw, 0, 'YXZ'),
    );
    this.group.quaternion.copy(this.alignedQuaternion).premultiply(tilt);
    this.group.updateMatrixWorld(true);
  }

  /** Slides the rig so the grip lands at `gripOffset` in camera space. */
  placeForCamera() {
    if (!this.gunBone) return;
    this.group.position.set(0, 0, 0);
    this.group.updateMatrixWorld(true);
    const grip = this.gunBone.getWorldPosition(new THREE.Vector3());
    // group is parented to the camera, whose world transform we want to ignore
    const parent = this.group.parent;
    if (parent) {
      parent.updateMatrixWorld(true);
      grip.applyMatrix4(new THREE.Matrix4().copy(parent.matrixWorld).invert());
    }
    this.group.position.copy(this.gripOffset).sub(grip);
    this.group.updateMatrixWorld(true);
  }

  /**
   * Puts `def`'s downloaded model in the hands. Returns false when the model has
   * not been loaded yet (the caller keeps whatever was there).
   */
  setWeapon(def) {
    if (!this.loaded) return false;
    if (this.weaponId === def.id) return true;
    this.weaponId = def.id;

    if (this.attached) {
      this.attached.parent?.remove(this.attached);
      this.attached = null;
    }

    const useOwnGun = def.id === 'ak47';
    if (this.gunMesh) this.gunMesh.visible = useOwnGun;
    if (useOwnGun) {
      this.gunRestLength = this.gunRestLength || 0.88;
      return true;
    }

    const model = instantiateWeaponModel(def);
    if (!model || !this.gunBone) return false;

    // Scale the incoming weapon to the AK's own length so the hands keep their
    // grip spacing, then attach it to the gun bone.
    const size = new THREE.Box3().setFromObject(model.group)
      .getSize(new THREE.Vector3());
    const target = def.modelLength;
    const current = Math.max(size.z, size.x, size.y);
    const fit = target / Math.max(1e-6, current);
    model.group.scale.multiplyScalar(fit);
    model.group.position.set(0, 0, 0);
    this.gunBone.add(model.group);

    // The weapon model is authored barrel-along-+Z in *world* terms, but it now
    // hangs off a bone that carries the asset's own 90-degree rig convention.
    // Solve for the local rotation that puts the barrel on the direction the
    // rig's own gun points, instead of hard-coding a guess.
    this.group.updateMatrixWorld(true);
    const boneWorld = this.gunBone.getWorldQuaternion(new THREE.Quaternion());
    const rigWorld = this.group.getWorldQuaternion(new THREE.Quaternion());
    const wantWorld = rigWorld.clone()
      .multiply(new THREE.Quaternion().setFromAxisAngle(new THREE.Vector3(0, 1, 0), Math.PI));
    model.group.quaternion.copy(boneWorld.clone().invert().multiply(wantWorld));
    model.group.updateMatrixWorld(true);
    this.attached = model.group;
    this.attachedMuzzle = model.muzzle;
    return true;
  }

  /** World-space muzzle. Falls back through the asset's own bones. */
  getMuzzleWorld(out = new THREE.Vector3()) {
    if (this.attached && this.attachedMuzzle) return this.attachedMuzzle.getWorldPosition(out);
    if (this.muzzleBone) return this.muzzleBone.getWorldPosition(out);
    return out.setFromMatrixPosition(this.group.matrixWorld);
  }

  play(name, fade = 0.08) {
    const next = this.actions[name];
    if (!next) return;
    if (this.current === next && name === 'idle') return;
    next.reset();
    next.setEffectiveWeight(1);
    next.play();
    if (this.current && this.current !== next) {
      this.current.crossFadeTo(next, fade, false);
    }
    this.current = next;
    this.currentName = name;
  }

  /** Trigger the real `fire` clip for one shot. */
  shoot() {
    if (!this.loaded) return;
    this._onFire = 0.28;
    const fire = this.actions.fire;
    if (!fire) return;
    fire.reset();
    fire.setEffectiveWeight(1);
    fire.play();
    if (this.actions.idle) {
      this.actions.idle.crossFadeTo(fire, 0.03, false);
      this.current = fire;
      this.currentName = 'fire';
    }
  }

  /** Trigger the real `reload_empty` clip, stretched to the weapon's reload time. */
  reload(duration) {
    const action = this.actions.reload_empty;
    if (!action) return;
    const clip = action.getClip();
    action.reset();
    action.timeScale = duration ? clip.duration / duration : 1;
    action.setEffectiveWeight(1);
    action.play();
    if (this.actions.idle) {
      this.actions.idle.crossFadeTo(action, 0.08, false);
      this.current = action;
      this.currentName = 'reload_empty';
    }
  }

  update(dt, { recoil = 0, aiming = false } = {}) {
    if (!this.loaded) return;
    this.mixer.update(dt);
    this._onFire = Math.max(0, this._onFire - dt);
    if (this._onFire === 0 && this.currentName === 'fire') {
      this.play('idle', 0.12);
    }
    // small procedural sway on top of the animated pose: recoil and ADS
    const sway = this._onFire > 0 ? Math.sin(this._onFire * 40) * 0.004 : 0;
    const ads = aiming ? 1 : 0;
    this.group.position.set(
      this._basePosition().x * (1 - ads * 0.72) + sway,
      this._basePosition().y * (1 - ads * 0.5) + recoil * 0.05,
      this._basePosition().z,
    );
  }

  _basePosition() {
    if (!this._base) this._base = this.group.position.clone();
    return this._base;
  }

  /** Recomputes the base offset after `placeForCamera` (called once loaded). */
  refreshBase() {
    this._base = this.group.position.clone();
  }
}
