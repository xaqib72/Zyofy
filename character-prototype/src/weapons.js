import * as THREE from 'three';

/**
 * Placeholder weapons.
 *
 * These are intentionally primitive primitives (boxes/cylinders): the task only
 * needs *something* in the hand to read the pistol / rifle / recoil / reload
 * animation. They are aligned to the hand bone procedurally from the aim pose so
 * they do not need manual per-character tuning.
 */

const bodyMat = new THREE.MeshStandardMaterial({ color: 0x23262b, metalness: 0.55, roughness: 0.45 });
const accentMat = new THREE.MeshStandardMaterial({ color: 0x3a3f45, metalness: 0.35, roughness: 0.6 });
const gripMat = new THREE.MeshStandardMaterial({ color: 0x17191c, metalness: 0.1, roughness: 0.85 });

function box(w, h, d, mat, x = 0, y = 0, z = 0) {
  const mesh = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), mat);
  mesh.position.set(x, y, z);
  mesh.castShadow = true;
  return mesh;
}

function cylinder(r, len, mat) {
  const mesh = new THREE.Mesh(new THREE.CylinderGeometry(r, r, len, 10), mat);
  mesh.rotation.x = Math.PI / 2; // point along +Z
  mesh.castShadow = true;
  return mesh;
}

/** Rifle: ~0.9 m long, origin (0,0,0) is the trigger/grip point, barrel points +Z. */
function buildRifle() {
  const g = new THREE.Group();
  g.add(box(0.055, 0.085, 0.34, bodyMat, 0, 0.02, 0.13));        // receiver
  g.add(box(0.05, 0.05, 0.2, gripMat, 0, -0.005, -0.06));         // stock
  const barrel = cylinder(0.011, 0.46, accentMat);
  barrel.position.set(0, 0.035, 0.5);
  g.add(barrel);
  const handguard = box(0.045, 0.045, 0.2, accentMat, 0, 0.03, 0.32);
  g.add(handguard);
  const mag = box(0.04, 0.16, 0.06, gripMat, 0, -0.09, 0.1);
  mag.rotation.x = -0.16;
  g.add(mag);
  const grip = box(0.035, 0.1, 0.05, gripMat, 0, -0.075, -0.03);
  grip.rotation.x = 0.35;
  g.add(grip);
  g.add(box(0.022, 0.03, 0.12, bodyMat, 0, 0.09, 0.14));          // optic
  const muzzle = new THREE.Object3D();
  muzzle.position.set(0, 0.035, 0.73);
  g.add(muzzle);
  return { group: g, muzzle, length: 0.9 };
}

/** Pistol: ~0.25 m long, origin at the grip, barrel points +Z. */
function buildPistol() {
  const g = new THREE.Group();
  g.add(box(0.032, 0.055, 0.2, bodyMat, 0, 0.02, 0.08));          // slide
  g.add(box(0.028, 0.03, 0.12, accentMat, 0, -0.012, 0.05));      // frame
  const grip = box(0.03, 0.11, 0.05, gripMat, 0, -0.07, -0.01);
  grip.rotation.x = 0.28;
  g.add(grip);
  g.add(box(0.02, 0.02, 0.03, accentMat, 0, 0.045, 0.02));        // rear sight
  const muzzle = new THREE.Object3D();
  muzzle.position.set(0, 0.02, 0.19);
  g.add(muzzle);
  return { group: g, muzzle, length: 0.28 };
}

/**
 * Parent `weapon` to `handBone` and compute the local transform that makes the
 * weapon point along the character's forward axis (+Z) while in the aim pose.
 * Call this while the rig is posed with the aim clip and the character is at
 * the origin with no rotation.
 */
export function alignWeaponToHand(weaponGroup, handBone, { pitch = -0.04 } = {}) {
  handBone.updateWorldMatrix(true, false);
  const handWorld = handBone.matrixWorld.clone();
  const handQ = handBone.getWorldQuaternion(new THREE.Quaternion());
  const handPos = handBone.getWorldPosition(new THREE.Vector3());
  const handScale = new THREE.Vector3();
  handWorld.decompose(new THREE.Vector3(), new THREE.Quaternion(), handScale);

  // Desired world orientation: barrel forward (+Z), tiny downward pitch.
  const desiredQ = new THREE.Quaternion().setFromEuler(new THREE.Euler(pitch, 0, 0));

  const localQ = handQ.clone().invert().multiply(desiredQ);
  const invHand = handWorld.clone().invert();
  const localP = handPos.clone().applyMatrix4(invHand);

  weaponGroup.quaternion.copy(localQ);
  weaponGroup.position.copy(localP);
  // keep the placeholder weapon at "real" size even if the rig has scaled bones
  weaponGroup.scale.set(1 / (handScale.x || 1), 1 / (handScale.y || 1), 1 / (handScale.z || 1));
  return weaponGroup;
}

/** Bullet tracer + muzzle flash, nothing else lives in the world. */
export class WeaponFX {
  constructor(scene) {
    this.scene = scene;
    this.items = [];
    this.flashLight = new THREE.PointLight(0xffd9a0, 0, 6, 2);
    this.flashLight.visible = false;
    scene.add(this.flashLight);
  }

  fire(muzzleWorld, direction, { tracerLength = 60 } = {}) {
    // tracer
    const end = muzzleWorld.clone().add(direction.clone().normalize().multiplyScalar(tracerLength));
    const geometry = new THREE.BufferGeometry().setFromPoints([muzzleWorld, end]);
    const material = new THREE.LineBasicMaterial({
      color: 0xffe9b0,
      transparent: true,
      opacity: 0.85,
      depthWrite: false,
    });
    const line = new THREE.Line(geometry, material);
    line.frustumCulled = false;
    this.scene.add(line);
    this.items.push({ object: line, life: 0.09, maxLife: 0.09, fade: 'opacity' });

    // muzzle flash
    this.flashLight.position.copy(muzzleWorld);
    this.flashLight.intensity = 14;
    this.flashLight.visible = true;
    this.flashLife = 0.05;

    const flashGeo = new THREE.PlaneGeometry(0.16, 0.16);
    const flashMat = new THREE.MeshBasicMaterial({
      color: 0xfff1c2,
      transparent: true,
      opacity: 0.95,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
      side: THREE.DoubleSide,
    });
    const flash = new THREE.Mesh(flashGeo, flashMat);
    flash.position.copy(muzzleWorld);
    flash.lookAt(muzzleWorld.clone().add(direction));
    this.scene.add(flash);
    this.items.push({ object: flash, life: 0.05, maxLife: 0.05, fade: 'opacity' });
  }

  update(dt) {
    if (this.flashLife !== undefined) {
      this.flashLife -= dt;
      this.flashLight.intensity = Math.max(0, this.flashLight.intensity - dt * 260);
      if (this.flashLife <= 0) {
        this.flashLight.visible = false;
        this.flashLight.intensity = 0;
      }
    }
    for (let i = this.items.length - 1; i >= 0; i--) {
      const item = this.items[i];
      item.life -= dt;
      const t = Math.max(0, item.life / item.maxLife);
      item.object.material.opacity = t * (item.fade === 'opacity' ? 0.95 : 1);
      if (item.life <= 0) {
        this.scene.remove(item.object);
        item.object.geometry.dispose();
        item.object.material.dispose();
        this.items.splice(i, 1);
      }
    }
  }
}

export function createWeapon(kind) {
  return kind === 'pistol' ? buildPistol() : buildRifle();
}
