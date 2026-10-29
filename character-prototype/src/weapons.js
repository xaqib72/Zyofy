import * as THREE from 'three';
import { instantiateWeaponModel } from './weaponAssets.js';

/**
 * Weapons and weapon effects.
 *
 * The weapon models are the downloaded GLBs in `public/assets/weapons/` -
 * adapted to the game by weaponAssets.js (orient, scale, muzzle, moving parts).
 * The effects are the downloaded Kenney particle atlas in `public/assets/fx/`.
 *
 * Every weapon exposes the same small interface:
 *   group   THREE.Group, origin at the grip, barrel pointing +Z
 *   muzzle  Object3D at the barrel tip (world position = where bullets leave)
 *   parts   { slide, bolt, pump, cylinder, mag } - the moving parts the model
 *           itself provides, empty when the asset has none
 *   length  metres, used for camera/viewmodel placement
 */

/* -------------------------------------------------------------------- factory */

/**
 * Builds the weapon that goes in the character's hand: a clone of the weapon's
 * downloaded GLB (see weaponAssets.js), already oriented with its barrel along
 * +Z, scaled to its real-world length and with its muzzle anchor at the bore.
 *
 * There is deliberately no procedural fallback geometry. If a GLB has not
 * finished loading the character simply holds nothing for that frame
 * (`kind: 'pending'`), and if a download fails the HUD lists it as failed -
 * `main.js` awaits every model before the first frame, so in practice this state
 * is only reachable in the half-second before the scene starts.
 */
export function buildWeapon(def, opts = {}) {
  void opts;
  const real = instantiateWeaponModel(def);
  if (real) {
    real.group.name = `weapon_${def.id}`;
    real.def = def;
    real.kind = 'asset';
    return real;
  }
  const placeholder = new THREE.Group();
  const muzzle = new THREE.Object3D();
  muzzle.position.set(0, 0, def.modelLength || 0.5);
  placeholder.add(muzzle);
  return {
    group: placeholder,
    muzzle,
    parts: {},
    length: def.modelLength || 0.5,
    kind: 'pending',
    def,
  };
}

/**
 * Parent a weapon to the right hand and compute the local transform that makes
 * the barrel point along the character's forward axis (+Z) in the aim pose.
 */
export function alignWeaponToHand(weaponGroup, handBone, { pitch = -0.04 } = {}) {
  handBone.updateWorldMatrix(true, false);
  const handWorld = handBone.matrixWorld.clone();
  const handQ = handBone.getWorldQuaternion(new THREE.Quaternion());
  const handPos = handBone.getWorldPosition(new THREE.Vector3());
  const handScale = new THREE.Vector3();
  handWorld.decompose(new THREE.Vector3(), new THREE.Quaternion(), handScale);

  const desiredQ = new THREE.Quaternion().setFromEuler(new THREE.Euler(pitch, 0, 0));
  const localQ = handQ.clone().invert().multiply(desiredQ);
  const invHand = handWorld.clone().invert();
  const localP = handPos.clone().applyMatrix4(invHand);

  weaponGroup.quaternion.copy(localQ);
  weaponGroup.position.copy(localP);
  weaponGroup.scale.set(
    1 / (handScale.x || 1),
    1 / (handScale.y || 1),
    1 / (handScale.z || 1),
  );
  return weaponGroup;
}

/**
 * First-person arms.
 *
 * The character is one skinned mesh, so its forearm geometry cannot be isolated
 * and re-parented to the camera. These are procedural stand-in arms used only in
 * first person: gloved hands and tapered forearms converging on the weapon, which
 * is what an FPS viewmodel needs. Documented as placeholders in the README.
 */
/**
 * First-person arms, built in *weapon-local* space so the hands land exactly on
 * the grips (right hand on the pistol grip, left hand on the foregrip) whatever
 * the weapon's proportions are. The forearms run back towards the camera.
 *
 * The character is a single skinned mesh, so its own forearm geometry cannot be
 * isolated and re-parented to the camera; these are procedural stand-ins used
 * only by the first-person viewmodel.
 */
/* ------------------------------------------------------------------------- FX */

/**
 * Muzzle flashes, tracers and impacts drawn from a downloaded sprite atlas.
 *
 * `public/assets/fx/particles.png` is the Kenney "Particle Pack" sheet (CC0) as
 * redistributed by the bagarre project: a 4 x 4 grid of 256 px cells. The cell
 * indices below are the pack's own (muzzle_01, muzzle_02, muzzle_04, muzzle_05,
 * spark, trace, scorch), so the effects are the artist's sprites rather than
 * shapes generated in code.
 */
export const PARTICLE_CELL = {
  muzzleLong: 0,
  muzzleRound: 1,
  muzzleRifle: 2,
  muzzleSmall: 3,
  spark1: 4,
  spark2: 5,
  streak: 6,
  star: 7,
  smoke1: 8,
  scorch1: 13,
  scorch2: 14,
  glow: 15,
};

export const PARTICLE_ATLAS = 'assets/fx/particles.png';

/** Per weapon class: which muzzle cell and how big the flash is (metres). */
const MUZZLE_LOOK = {
  pistol: { cell: PARTICLE_CELL.muzzleRound, w: 0.3, h: 0.42 },
  heavy: { cell: PARTICLE_CELL.muzzleRound, w: 0.42, h: 0.55 },
  revolver: { cell: PARTICLE_CELL.muzzleRound, w: 0.36, h: 0.5 },
  smg: { cell: PARTICLE_CELL.muzzleSmall, w: 0.26, h: 0.36 },
  rifle: { cell: PARTICLE_CELL.muzzleRifle, w: 0.42, h: 0.8 },
  dmr: { cell: PARTICLE_CELL.muzzleRifle, w: 0.44, h: 0.85 },
  sniper: { cell: PARTICLE_CELL.muzzleLong, w: 0.5, h: 1.15 },
  shotgun: { cell: PARTICLE_CELL.muzzleRound, w: 0.55, h: 0.7 },
  lmg: { cell: PARTICLE_CELL.muzzleRifle, w: 0.45, h: 0.85 },
  knife: { cell: PARTICLE_CELL.star, w: 0.1, h: 0.1 },
};

/** UV rect of one atlas cell (row 0 is the top of the image). */
/** A quad whose UVs address a single cell of the atlas. */
function cellQuad() {
  const geometry = new THREE.PlaneGeometry(1, 1);
  const uv = geometry.attributes.uv;
  for (let i = 0; i < uv.count; i += 1) uv.setXY(i, 0, 0);
  return geometry;
}

export function makeCellGeometry(cell) {
  const geometry = cellQuad();
  const uv = geometry.attributes.uv;
  const corners = // PlaneGeometry vertex order: TL, TR, BL, BR
    [[0, 1], [1, 1], [0, 0], [1, 0]];
  const cx = cell % 4;
  const cy = Math.floor(cell / 4);
  for (let i = 0; i < uv.count; i += 1) {
    const [u, v] = corners[i];
    uv.setXY(i, (cx + u) / 4, 1 - (cy + (1 - v)) / 4);
  }
  uv.needsUpdate = true;
  return geometry;
}

export class WeaponFX {
  constructor(scene) {
    this.scene = scene;
    this.items = [];
    this.decals = [];
    this.flashLight = new THREE.PointLight(0xffd9a0, 0, 7, 2);
    this.flashLight.visible = false;
    scene.add(this.flashLight);
    this.flashLife = 0;

    this.atlas = new THREE.TextureLoader().load(PARTICLE_ATLAS);
    this.atlas.colorSpace = THREE.SRGBColorSpace;
    this.atlasReady = false;
    this.atlas.addEventListener('load', () => { this.atlasReady = true; });

    this.flashMaterial = new THREE.MeshBasicMaterial({
      map: this.atlas,
      transparent: true,
      opacity: 0.95,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
      side: THREE.DoubleSide,
      color: 0xfff0c0,
    });
    this.tracerMaterial = new THREE.MeshBasicMaterial({
      map: this.atlas,
      transparent: true,
      opacity: 0.75,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
      side: THREE.DoubleSide,
      color: 0xffe6a8,
    });
    this.scorchMaterial = new THREE.MeshBasicMaterial({
      map: this.atlas,
      transparent: true,
      opacity: 0.85,
      depthWrite: false,
      side: THREE.DoubleSide,
      color: 0x2a2a2a,
    });
    this.sparkMaterial = new THREE.MeshBasicMaterial({
      map: this.atlas,
      transparent: true,
      opacity: 1,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
      side: THREE.DoubleSide,
      color: 0xffd27a,
    });
  }

  _sprite(cell, material, position, quaternion, size, life) {
    const mesh = new THREE.Mesh(makeCellGeometry(cell), material.clone());
    mesh.position.copy(position);
    if (quaternion) mesh.quaternion.copy(quaternion);
    mesh.scale.set(size.w, size.h, 1);
    mesh.renderOrder = 3;
    this.scene.add(mesh);
    this.items.push({ object: mesh, life, maxLife: life, ownMaterial: true, size });
    return mesh;
  }

  /**
   * Muzzle flash + tracer along the real shot direction, using the atlas cells
   * for the weapon's class.
   */
  fire(muzzleWorld, direction, { sound = 'rifle', tracerLength = 60, scale = 1 } = {}) {
    const look = MUZZLE_LOOK[sound] || MUZZLE_LOOK.rifle;
    const dir = direction.clone().normalize();

    // billboard the flash across the shot direction
    const q = new THREE.Quaternion().setFromUnitVectors(new THREE.Vector3(0, 0, 1), dir);
    this._sprite(look.cell, this.flashMaterial, muzzleWorld, q,
      { w: look.w * scale, h: look.h * scale }, 0.055);

    // glowing ball around the muzzle, so the flash reads from any angle
    this._sprite(PARTICLE_CELL.glow, this.flashMaterial, muzzleWorld, null,
      { w: look.w * 1.6 * scale, h: look.w * 1.6 * scale }, 0.05);

    // tracer: a stretched `trace` cell running down the shot direction
    const end = muzzleWorld.clone().addScaledVector(dir, tracerLength);
    const mid = muzzleWorld.clone().addScaledVector(dir, tracerLength * 0.5);
    const tracerQ = new THREE.Quaternion().setFromUnitVectors(new THREE.Vector3(0, 1, 0), dir);
    const tracer = this._sprite(PARTICLE_CELL.streak, this.tracerMaterial, mid, tracerQ,
      { w: 0.05, h: tracerLength }, 0.07);
    tracer.scale.set(0.05, tracerLength, 1);
    void end;

    this.flashLight.position.copy(muzzleWorld);
    this.flashLight.intensity = 14 * scale;
    this.flashLight.visible = true;
    this.flashLife = 0.05;
  }

  /** Scorch sprite on the surface plus a couple of spark sprites at the hit. */
  impact(point, normal) {
    const n = normal.clone().normalize();
    const q = new THREE.Quaternion().setFromUnitVectors(new THREE.Vector3(0, 0, 1), n);
    const at = point.clone().addScaledVector(n, 0.004);
    const scorch = new THREE.Mesh(
      makeCellGeometry(PARTICLE_CELL.scorch1), this.scorchMaterial.clone(),
    );
    scorch.position.copy(at);
    scorch.quaternion.copy(q);
    scorch.scale.setScalar(0.14 + Math.random() * 0.06);
    scorch.renderOrder = 2;
    this.scene.add(scorch);
    this.decals.push({ object: scorch, life: 8 });
    if (this.decals.length > 60) {
      const old = this.decals.shift();
      this.scene.remove(old.object);
      old.object.geometry.dispose();
      old.object.material.dispose();
    }

    for (let i = 0; i < 3; i += 1) {
      const jitter = new THREE.Vector3(
        (Math.random() - 0.5) * 0.06, (Math.random() - 0.5) * 0.06, (Math.random() - 0.5) * 0.06,
      );
      this._sprite(i % 2 ? PARTICLE_CELL.spark1 : PARTICLE_CELL.spark2, this.sparkMaterial,
        at.clone().add(jitter), q, { w: 0.16, h: 0.16 }, 0.09);
    }
  }

  update(dt) {
    if (this.flashLife > 0) {
      this.flashLife -= dt;
      this.flashLight.intensity = Math.max(0, this.flashLight.intensity - dt * 300);
      if (this.flashLife <= 0) {
        this.flashLight.visible = false;
        this.flashLight.intensity = 0;
      }
    }
    for (let i = this.items.length - 1; i >= 0; i -= 1) {
      const item = this.items[i];
      item.life -= dt;
      const k = Math.max(0, item.life / item.maxLife);
      item.object.material.opacity = k * 0.95;
      if (item.size) item.object.scale.multiplyScalar(1 + dt * 2.5);
      if (item.life <= 0) {
        this.scene.remove(item.object);
        item.object.geometry.dispose();
        if (item.ownMaterial) item.object.material.dispose();
        this.items.splice(i, 1);
      }
    }
    for (let i = this.decals.length - 1; i >= 0; i -= 1) {
      const d = this.decals[i];
      d.life -= dt;
      if (d.life <= 0) {
        this.scene.remove(d.object);
        d.object.geometry.dispose();
        d.object.material.dispose();
        this.decals.splice(i, 1);
      }
    }
  }
}
