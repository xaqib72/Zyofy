import * as THREE from 'three';

/**
 * Procedural weapon models.
 *
 * The environment has no reachable library of free weapon GLBs (Sketchfab needs
 * an authenticated download, the usual "free weapon pack" sites are either paid
 * or behind logins, and the CC0 model sets that are mirrored on GitHub do not
 * include firearms), so the collection is built from primitives, driven by the
 * per-weapon parameters in weaponCatalog.js. Different parameter sets produce
 * genuinely different geometry - barrel length and gauge, receiver volume,
 * stock type, magazine type (box / curved / drum / belt / tube), optics,
 * suppressors, pump and bolt hardware, bipods - which is what makes each weapon
 * readable and distinguishable in both camera modes.
 *
 * Every weapon exposes the same small interface:
 *   group   THREE.Group, origin at the grip, barrel pointing +Z
 *   muzzle  Object3D at the barrel tip (world position = where bullets leave)
 *   parts   { slide, bolt, pump, cylinder, mag } optional moving parts
 *   length  metres, used for third-person camera offsets
 */

const MAT = {
  body: new THREE.MeshStandardMaterial({ color: 0x24262b, metalness: 0.6, roughness: 0.42 }),
  bodyTan: new THREE.MeshStandardMaterial({ color: 0x6e6a52, metalness: 0.35, roughness: 0.6 }),
  bodyWood: new THREE.MeshStandardMaterial({ color: 0x6b4a2f, metalness: 0.05, roughness: 0.85 }),
  steel: new THREE.MeshStandardMaterial({ color: 0x8d9298, metalness: 0.85, roughness: 0.3 }),
  polymer: new THREE.MeshStandardMaterial({ color: 0x17191c, metalness: 0.1, roughness: 0.85 }),
  blade: new THREE.MeshStandardMaterial({ color: 0xb9bfc6, metalness: 0.9, roughness: 0.18 }),
};

function bodyMat(kind, w) {
  if (w.model.wood) return MAT.bodyWood;
  if (w.model.tan) return MAT.bodyTan;
  if (kind === 'knife') return MAT.polymer;
  void kind;
  return new THREE.MeshStandardMaterial({
    color: w.model.colour ?? 0x24262b,
    metalness: 0.55,
    roughness: 0.45,
  });
}

function box(w, h, d, mat, x = 0, y = 0, z = 0) {
  const mesh = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), mat);
  mesh.position.set(x, y, z);
  mesh.castShadow = true;
  return mesh;
}

function tube(radius, length, mat, x = 0, y = 0, z = 0, segments = 10) {
  const mesh = new THREE.Mesh(new THREE.CylinderGeometry(radius, radius, length, segments), mat);
  mesh.rotation.x = Math.PI / 2;
  mesh.position.set(x, y, z);
  mesh.castShadow = true;
  return mesh;
}

/* --------------------------------------------------------------- weapon kinds */

function buildPistol(w) {
  const g = new THREE.Group();
  const m = w.model;
  const body = bodyMat('pistol', w);
  const frameY = 0.02;
  const barrelLen = m.barrel ?? 0.12;
  const width = m.wide ? 0.04 : 0.032;

  const slide = box(width, m.wide ? 0.07 : 0.055, barrelLen + 0.08, body, 0, frameY, 0.02);
  g.add(slide);

  g.add(box(width * 0.85, 0.03, barrelLen, MAT.polymer, 0, -0.014, 0.02)); // frame

  const grip = box(width * 0.92, 0.115, 0.05, MAT.polymer, 0, -0.075, -0.035);
  grip.rotation.x = 0.26;
  g.add(grip);

  // magazine (inside the grip, slides out for the reload animation)
  const mag = box(width * 0.8, 0.1, 0.042, MAT.steel, 0, -0.07, -0.032);
  mag.rotation.x = 0.26;
  g.add(mag);

  g.add(box(0.02, 0.018, 0.02, MAT.steel, 0, 0.052, -0.02)); // rear sight
  g.add(box(0.008, 0.014, 0.01, MAT.steel, 0, 0.052, barrelLen + 0.01)); // front sight

  if (m.exposed) g.add(box(width * 0.6, 0.026, barrelLen * 0.7, MAT.steel, 0, 0.04, 0.05)); // exposed barrel
  if (m.suppressor) {
    g.add(tube(0.026, 0.16, MAT.polymer, 0, frameY, barrelLen + 0.13, 12));
  }

  const muzzleZ = barrelLen + (m.suppressor ? 0.22 : 0.06);
  const muzzle = new THREE.Object3D();
  muzzle.position.set(0, frameY, muzzleZ);
  g.add(muzzle);

  return { group: g, muzzle, parts: { slide, mag }, length: 0.28, pose: 'short' };
}

function buildRevolver(w) {
  const g = new THREE.Group();
  const m = w.model;
  const body = bodyMat('revolver', w);
  const barrelLen = m.barrel ?? 0.18;

  g.add(tube(0.013, barrelLen, MAT.steel, 0, 0.03, barrelLen / 2 + 0.04, 12)); // barrel
  g.add(box(0.022, 0.022, barrelLen * 0.8, body, 0, 0.012, barrelLen / 2 + 0.02)); // underlug
  if (m.vents) g.add(box(0.006, 0.03, barrelLen * 0.5, body, 0, 0.045, barrelLen * 0.5));

  const cylinder = tube(0.032, m.stubby ? 0.05 : 0.06, MAT.steel, 0, 0.028, 0.02, 12);
  g.add(cylinder);

  const grip = box(0.028, 0.115, 0.05, MAT.bodyWood, 0, -0.06, -0.05);
  grip.rotation.x = 0.34;
  g.add(grip);

  g.add(box(0.01, 0.02, 0.03, MAT.steel, 0, 0.07, -0.03)); // hammer

  const muzzle = new THREE.Object3D();
  muzzle.position.set(0, 0.03, barrelLen + 0.05);
  g.add(muzzle);

  return { group: g, muzzle, parts: { cylinder }, length: 0.3, pose: 'short' };
}

function addStock(g, m, receiverZ) {
  if (m.omitStock) return; // first-person viewmodel: the stock is in the shoulder
  const mat = m.wood ? MAT.bodyWood : MAT.body;
  switch (m.stock) {
    case 'fixed':
      g.add(box(0.04, 0.09, 0.26, mat, 0, -0.015, receiverZ - 0.22));
      g.add(box(0.045, 0.11, 0.03, MAT.polymer, 0, -0.02, receiverZ - 0.36));
      break;
    case 'folding':
      g.add(box(0.03, 0.06, 0.2, mat, 0, -0.01, receiverZ - 0.19));
      g.add(box(0.012, 0.05, 0.14, MAT.steel, 0.03, 0.01, receiverZ - 0.12));
      break;
    case 'retract':
      g.add(tube(0.016, 0.16, MAT.steel, 0, -0.005, receiverZ - 0.16));
      g.add(box(0.05, 0.07, 0.06, MAT.polymer, 0, -0.01, receiverZ - 0.24));
      break;
    case 'wire':
      g.add(box(0.008, 0.07, 0.16, MAT.steel, 0.025, -0.01, receiverZ - 0.15));
      g.add(box(0.008, 0.07, 0.16, MAT.steel, -0.025, -0.01, receiverZ - 0.15));
      g.add(box(0.06, 0.05, 0.02, MAT.steel, 0, -0.02, receiverZ - 0.24));
      break;
    default:
      break;
  }
}

function addMagazine(g, m, receiverZ, mat) {
  switch (m.mag) {
    case 'curved': {
      const mag = box(0.032, 0.17, 0.055, mat, 0, -0.11, receiverZ + 0.02);
      mag.rotation.x = -0.28;
      g.add(mag);
      break;
    }
    case 'box':
      g.add(box(0.032, 0.15, 0.05, mat, 0, -0.1, receiverZ + 0.02));
      break;
    case 'drum': {
      const drum = tube(0.075, 0.06, mat, 0, -0.13, receiverZ + 0.02, 14);
      drum.rotation.z = Math.PI / 2;
      g.add(drum);
      break;
    }
    case 'belt':
      g.add(box(0.06, 0.13, 0.1, mat, 0, -0.11, receiverZ + 0.01));
      break;
    case 'tube':
      g.add(tube(0.017, 0.42, mat, 0, -0.045, receiverZ + 0.24, 10)); // under-barrel tube
      break;
    default:
      break;
  }
}

function buildLongGun(w, opts = {}) {
  const g = new THREE.Group();
  const m = { ...w.model, omitStock: !!opts.viewmodel };
  const body = bodyMat('rifle', w);
  const magMat = m.wood ? MAT.steel : MAT.polymer;
  const kind = m.kind;

  const receiverLen = kind === 'smg' ? 0.16 : kind === 'lmg' ? 0.22 : 0.19;
  const receiverZ = 0;
  const receiver = box(0.045, 0.085, receiverLen, body, 0, 0, receiverZ);
  g.add(receiver);

  // upper rail / carry handle
  if (m.rail) g.add(box(0.03, 0.012, receiverLen * 0.9, MAT.steel, 0, 0.048, receiverZ));
  if (m.carry) g.add(box(0.018, 0.02, 0.1, MAT.steel, 0, 0.06, receiverZ + 0.02));

  // barrel + handguard
  const barrelLen = m.barrel ?? 0.35;
  const barrelStart = receiverLen / 2 + receiverZ;
  g.add(tube(kind === 'shotgun' ? 0.014 : 0.0095, barrelLen, MAT.steel, 0, 0.012, barrelStart + barrelLen / 2, 10));
  const guardMat = m.wood ? MAT.bodyWood : MAT.body;
  g.add(box(0.05, 0.055, barrelLen * 0.62, guardMat, 0, 0.004, barrelStart + barrelLen * 0.28));

  if (m.suppressor) g.add(tube(0.026, 0.17, MAT.polymer, 0, 0.012, barrelStart + barrelLen + 0.075, 12));
  if (m.gasBlock) g.add(box(0.03, 0.045, 0.05, MAT.steel, 0, 0.03, barrelStart + barrelLen * 0.75));
  if (m.heatShield) g.add(box(0.055, 0.06, barrelLen * 0.5, MAT.polymer, 0, 0.006, barrelStart + barrelLen * 0.22));

  // muzzle device
  g.add(tube(0.014, 0.045, MAT.steel, 0, 0.012, barrelStart + barrelLen + 0.02, 10));

  // grip / trigger guard
  const grip = box(0.036, 0.11, 0.05, MAT.polymer, 0, -0.075, receiverZ - 0.02);
  grip.rotation.x = 0.34;
  g.add(grip);
  g.add(box(0.03, 0.008, 0.06, MAT.steel, 0, -0.045, receiverZ + 0.05));

  if (m.foregrip) g.add(box(0.028, 0.09, 0.04, MAT.polymer, 0, -0.07, barrelStart + 0.08));
  if (m.angled) {
    const afg = box(0.03, 0.075, 0.035, MAT.polymer, 0, -0.065, barrelStart + 0.1);
    afg.rotation.x = -0.5;
    g.add(afg);
  }
  if (m.grip === 'mag') g.add(box(0.032, 0.1, 0.045, MAT.polymer, 0, -0.12, receiverZ + 0.08));

  addStock(g, m, receiverZ - receiverLen / 2);
  addMagazine(g, m, receiverZ, magMat);

  // optics
  if (m.optic === 'scope') {
    g.add(box(0.03, 0.02, 0.05, MAT.steel, 0, 0.055, receiverZ + 0.04));
    g.add(tube(0.024, 0.22, MAT.steel, 0, 0.085, receiverZ + 0.04, 12));
    g.add(tube(0.03, 0.04, MAT.steel, 0, 0.085, receiverZ + 0.16, 12));
  } else if (m.optic === 'reddot') {
    g.add(box(0.034, 0.04, 0.06, MAT.polymer, 0, 0.06, receiverZ + 0.05));
  } else {
    g.add(box(0.012, 0.02, 0.02, MAT.steel, 0, 0.05, receiverZ + 0.06)); // irons
  }

  // moving parts -------------------------------------------------------------
  const parts = {};

  if (m.bolt) {
    const bolt = box(0.012, 0.012, 0.09, MAT.steel, 0.028, 0.018, receiverZ + 0.06);
    const knob = new THREE.Mesh(new THREE.SphereGeometry(0.014, 8, 8), MAT.steel);
    knob.position.set(0.075, 0.018, receiverZ + 0.1);
    const boltGroup = new THREE.Group();
    boltGroup.add(bolt, knob);
    g.add(boltGroup);
    parts.bolt = boltGroup;
  }

  if (m.pump) {
    const pump = box(0.058, 0.05, 0.12, m.wood ? MAT.bodyWood : MAT.polymer, 0, -0.012, barrelStart + barrelLen * 0.24);
    g.add(pump);
    parts.pump = pump;
  }

  if (m.mag === 'box' || m.mag === 'curved' || m.mag === 'drum') {
    const magMesh = g.children.find((c) => c.geometry && c.geometry.type === 'BoxGeometry'
      && c.position.y < -0.08 && Math.abs(c.position.z - receiverZ) < 0.1);
    if (magMesh) parts.mag = magMesh;
  }

  if (m.bipod) {
    g.add(box(0.008, 0.12, 0.01, MAT.steel, 0.025, -0.08, barrelStart + 0.1));
    g.add(box(0.008, 0.12, 0.01, MAT.steel, -0.025, -0.08, barrelStart + 0.1));
  }

  if (kind === 'lmg' && m.mag === 'belt') {
    g.add(box(0.09, 0.09, 0.12, MAT.steel, 0, -0.03, receiverZ + 0.02)); // feed tray
  }

  const muzzleLen = barrelStart + barrelLen + (m.suppressor ? 0.21 : 0.05);
  const muzzle = new THREE.Object3D();
  muzzle.position.set(0, 0.012, muzzleLen);
  g.add(muzzle);

  return { group: g, muzzle, parts, length: muzzleLen + 0.3, pose: 'long' };
}

function buildKnife(w) {
  const g = new THREE.Group();
  const m = w.model;
  const bladeLen = m.blade ?? 0.18;

  const blade = new THREE.Mesh(new THREE.BoxGeometry(0.006, 0.032, bladeLen), MAT.blade);
  blade.position.set(0, 0.008, bladeLen / 2 + 0.03);
  blade.castShadow = true;
  g.add(blade);

  if (m.serrated) {
    for (let i = 0; i < 5; i += 1) {
      g.add(box(0.008, 0.008, 0.012, MAT.blade, 0, 0.026, 0.06 + i * 0.02));
    }
  }
  if (m.machete) {
    const wide = new THREE.Mesh(new THREE.BoxGeometry(0.007, 0.07, bladeLen), MAT.blade);
    wide.position.set(0, 0.005, bladeLen / 2 + 0.03);
    wide.castShadow = true;
    g.add(wide);
  }

  g.add(box(0.014, 0.012, 0.02, MAT.steel, 0, 0.008, 0.03)); // guard / ricasso
  const handle = box(0.022, 0.028, 0.11, MAT.polymer, 0, 0.004, -0.035);
  handle.rotation.x = 0.06;
  g.add(handle);

  const muzzle = new THREE.Object3D();
  muzzle.position.set(0, 0.008, bladeLen + 0.04);
  g.add(muzzle);
  return { group: g, muzzle, parts: {}, length: 0.3, pose: 'melee' };
}

/* -------------------------------------------------------------------- factory */

export function buildWeapon(def, opts = {}) {
  let built;
  switch (def.model.kind) {
    case 'pistol': built = buildPistol(def); break;
    case 'revolver': built = buildRevolver(def); break;
    case 'knife': built = buildKnife(def); break;
    default: built = buildLongGun(def, opts);
  }
  built.group.name = `weapon_${def.id}`;
  built.def = def;
  return built;
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
export function buildViewArms(isLong, foregripZ = 0.3) {
  const group = new THREE.Group();
  group.name = 'viewmodel_arms';
  const sleeve = new THREE.MeshStandardMaterial({ color: 0x6f6558, roughness: 0.88, metalness: 0.04 });
  const glove = new THREE.MeshStandardMaterial({ color: 0x23252b, roughness: 0.78, metalness: 0.05 });

  const forearm = (from, to, radiusA, radiusB) => {
    const dir = to.clone().sub(from);
    const len = dir.length();
    const mesh = new THREE.Mesh(new THREE.CylinderGeometry(radiusB, radiusA, len, 10), sleeve);
    mesh.position.copy(from).addScaledVector(dir, 0.5);
    mesh.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), dir.clone().normalize());
    return mesh;
  };

  const hand = (at, size = [0.075, 0.09, 0.13]) => {
    const mesh = new THREE.Mesh(new THREE.BoxGeometry(...size), glove);
    mesh.position.copy(at);
    return mesh;
  };

  // right hand: on the pistol grip, forearm going back and down to the camera
  const gripPoint = new THREE.Vector3(0, -0.055, -0.02);
  group.add(hand(gripPoint));
  group.add(forearm(new THREE.Vector3(0.0, -0.09, -0.02), new THREE.Vector3(0.075, -0.30, 0.34), 0.045, 0.036));

  // left hand: on the foregrip / handguard, forearm back to the lower left
  const foreZ = isLong ? foregripZ : foregripZ * 0.7;
  const forePoint = new THREE.Vector3(0, -0.05, foreZ);
  group.add(hand(forePoint, [0.08, 0.075, 0.12]));
  group.add(forearm(new THREE.Vector3(0.0, -0.075, foreZ), new THREE.Vector3(-0.14, -0.34, 0.42), 0.048, 0.038));

  return group;
}

/* ------------------------------------------------------------------------- FX */

/** Muzzle flash, tracers and bullet impact marks. */
export class WeaponFX {
  constructor(scene) {
    this.scene = scene;
    this.items = [];
    this.decals = [];
    this.flashLight = new THREE.PointLight(0xffd9a0, 0, 7, 2);
    this.flashLight.visible = false;
    scene.add(this.flashLight);
    this.flashLife = 0;

    this.flashGeo = new THREE.PlaneGeometry(0.2, 0.2);
    this.flashMat = new THREE.MeshBasicMaterial({
      color: 0xfff1c2,
      transparent: true,
      opacity: 0.95,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
      side: THREE.DoubleSide,
    });
    this.decalGeo = new THREE.CircleGeometry(0.035, 10);
    this.decalMat = new THREE.MeshBasicMaterial({ color: 0x1a1a1a, side: THREE.DoubleSide });
  }

  fire(muzzleWorld, direction, { tracerLength = 60, scale = 1 } = {}) {
    const end = muzzleWorld.clone().add(direction.clone().normalize().multiplyScalar(tracerLength));
    const geometry = new THREE.BufferGeometry().setFromPoints([muzzleWorld, end]);
    const material = new THREE.LineBasicMaterial({
      color: 0xffe9b0, transparent: true, opacity: 0.85, depthWrite: false,
    });
    const line = new THREE.Line(geometry, material);
    line.frustumCulled = false;
    this.scene.add(line);
    this.items.push({ object: line, life: 0.08, maxLife: 0.08 });

    this.flashLight.position.copy(muzzleWorld);
    this.flashLight.intensity = 14 * scale;
    this.flashLight.visible = true;
    this.flashLife = 0.05;

    const flash = new THREE.Mesh(this.flashGeo, this.flashMat.clone());
    flash.position.copy(muzzleWorld);
    flash.lookAt(muzzleWorld.clone().add(direction));
    flash.scale.setScalar(scale);
    this.scene.add(flash);
    this.items.push({ object: flash, life: 0.05, maxLife: 0.05, ownMaterial: true });
  }

  /** Dark disc + tiny sparks where a shot landed. */
  impact(point, normal) {
    const decal = new THREE.Mesh(this.decalGeo, this.decalMat.clone());
    decal.position.copy(point).add(normal.clone().multiplyScalar(0.004));
    decal.lookAt(point.clone().add(normal));
    this.scene.add(decal);
    this.decals.push({ object: decal, life: 6 });
    if (this.decals.length > 60) {
      const old = this.decals.shift();
      this.scene.remove(old.object);
      old.object.material.dispose();
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
      item.object.material.opacity = Math.max(0, item.life / item.maxLife) * 0.95;
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
        d.object.material.dispose();
        this.decals.splice(i, 1);
      }
    }
  }
}
