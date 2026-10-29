#!/usr/bin/env node
/**
 * Weapon system verification.
 *
 *  1. every catalogue entry builds a model, and the models are genuinely
 *     different from one another (silhouette dimensions + moving parts), so the
 *     collection is not one gun renamed 30 times;
 *  2. every weapon mounts with its muzzle in front of the hand and its barrel
 *     pointing along the character's forward axis;
 *  3. every weapon's mechanical parts animate for its reload style.
 *
 * Run:  npm run verify-weapons
 */
import path from 'node:path';
import * as THREE from 'three';
import { Character } from '../src/character.js';
import { CHARACTERS, CLIP_DEFS } from '../src/config.js';
import { WEAPON_LIST, WEAPONS } from '../src/weaponCatalog.js';
import { buildWeapon } from '../src/weapons.js';
import { parseGLB } from './lib/glb-browser.mjs';
import { findBone } from '../src/retarget.js';

const ROOT = path.resolve(import.meta.dirname, '..');
let failures = 0;
let checks = 0;
const check = (label, ok, detail = '') => {
  checks += 1;
  if (!ok) failures += 1;
  console.log(`${ok ? ' ok ' : 'FAIL'}  ${label}${detail ? `   ${detail}` : ''}`);
};

/* ------------------------------------------------ 1. models are distinct */

console.log('=== catalogue ===');
check('catalogue has 20+ distinct weapons', WEAPON_LIST.length >= 20, `${WEAPON_LIST.length} weapons`);

const categories = new Map();
for (const def of WEAPON_LIST) {
  categories.set(def.category, (categories.get(def.category) || 0) + 1);
}
const required = ['pistol', 'revolver', 'smg', 'ar', 'battle', 'marksman', 'sniper', 'shotgun', 'lmg', 'melee'];
for (const cat of required) {
  check(`category "${cat}" present`, (categories.get(cat) || 0) > 0, `${categories.get(cat) || 0} weapons`);
}

const signatures = new Map();
const dims = [];
for (const def of WEAPON_LIST) {
  const built = buildWeapon(def);
  built.group.updateMatrixWorld(true);
  const box3 = new THREE.Box3().setFromObject(built.group);
  const size = new THREE.Vector3();
  box3.getSize(size);
  let meshes = 0;
  built.group.traverse((o) => { if (o.isMesh) meshes += 1; });
  const parts = Object.keys(built.parts).sort().join(',');
  const signature = `${size.x.toFixed(2)}x${size.y.toFixed(2)}x${size.z.toFixed(2)}|${meshes}|${parts}`;
  if (signatures.has(signature)) {
    check(`${def.label} has a unique model`, false, `identical to ${signatures.get(signature)}`);
  } else {
    signatures.set(signature, def.label);
  }
  dims.push({ id: def.id, len: size.z, meshes, parts });
  check(`${def.label}: muzzle sits at the far (+Z) end of the model`,
    built.muzzle.position.z >= box3.max.z - 0.06,
    `muzzle z ${built.muzzle.position.z.toFixed(2)} / model ${box3.max.z.toFixed(2)}`);
}
check('all weapon models are geometrically distinct', signatures.size === WEAPON_LIST.length,
  `${signatures.size}/${WEAPON_LIST.length} unique silhouettes`);

const lengths = dims.map((d) => d.len);
check('model lengths vary across the collection',
  Math.max(...lengths) / Math.min(...lengths) > 2.5,
  `${Math.min(...lengths).toFixed(2)} m … ${Math.max(...lengths).toFixed(2)} m`);

/* ------------------------------------------------ 2. mounting per weapon */

console.log('\n=== mounting on both rigs ===');
const sourceClips = new Map();
for (const def of CLIP_DEFS) {
  const gltf = await parseGLB(path.join(ROOT, 'public/assets/animations', `${def.id}.glb`));
  sourceClips.set(def.id, { gltf: gltf.scene, clip: gltf.animations[0], def });
}

for (const charDef of CHARACTERS) {
  const gltf = await parseGLB(path.join(ROOT, 'public/assets/characters', path.basename(charDef.file)));
  const character = new Character(charDef, gltf);
  character.prepareRetargeting(sourceClips);
  character.bakeMotion(sourceClips);
  character.setupWeapons(new THREE.Scene());

  const bad = [];
  for (const def of WEAPON_LIST) {
    character.setWeapon(def.id);
    // sample the aim pose so the weapon is in its aligned orientation
    const aimClip = def.firePose === 'long' ? 'rifle_aim_idle'
      : def.firePose === 'melee' ? 'knife_idle' : 'pistol_aim_idle';
    character.mixer.stopAllAction();
    character.actions.get(aimClip).reset().play();
    character.mixer.setTime(0.05);
    character.root.updateMatrixWorld(true);
    character.group.updateMatrixWorld(true);

    const muzzle = character.getMuzzleWorld(new THREE.Vector3());
    const hand = character.bones.rightHand.getWorldPosition(new THREE.Vector3());
    const forward = new THREE.Vector3(0, 0, 1).applyQuaternion(character.group.quaternion);

    const delta = muzzle.clone().sub(hand);
    const along = delta.dot(forward);
    const lateral = delta.clone().addScaledVector(forward, -along).length();

    if (along < 0.15) bad.push(`${def.id}: muzzle behind hand (${along.toFixed(2)} m)`);
    else if (lateral > 0.4) bad.push(`${def.id}: muzzle off axis (${lateral.toFixed(2)} m)`);
    else if (muzzle.y < 0.35) bad.push(`${def.id}: muzzle too low (${muzzle.y.toFixed(2)} m)`);
  }
  check(`${charDef.label}: all ${WEAPON_LIST.length} weapons mount correctly`, bad.length === 0,
    bad.slice(0, 3).join(' | ') || 'muzzle ahead of hand, on the forward axis');

  /* --------------------------------------- 3. mechanical parts animate */

  const mechanicFailures = [];
  for (const def of WEAPON_LIST) {
    character.setWeapon(def.id);
    const weapon = character.weapons[def.id];
    const parts = Object.keys(weapon.parts);
    if (!parts.length) continue;

    if (def.reloadStyle !== 'none') {
      character.beginReloadMechanics(def.reloadStyle, 1.0);
      character.updateWeaponMechanics(0.02, null);
      character.updateWeaponMechanics(0.5, null);
      const moved = parts.some((name) => {
        const part = weapon.parts[name];
        const base = part.userData.basePosition;
        return part.position.distanceTo(base) > 0.001
          || Math.abs(part.rotation.z - part.userData.baseRotation.z) > 0.01;
      });
      if (!moved) mechanicFailures.push(`${def.id} (${def.reloadStyle})`);
      character.endReloadMechanics();
    }
  }
  check(`${charDef.label}: reload mechanics move the right parts`, mechanicFailures.length === 0,
    mechanicFailures.slice(0, 4).join(', ') || 'cylinder / mag / bolt / pump all animate');
}

/* ------------------------------------------------ fire-mode configuration */

console.log('\n=== firing behaviour configuration ===');
for (const def of WEAPON_LIST) {
  if (def.mode === 'melee') {
    check(`${def.label}: melee weapon has no ammunition`, def.magSize === 0 && def.reloadStyle === 'none');
  } else {
    const interval = 60 / def.rpm;
    check(`${def.label}: ${def.mode} ${def.rpm} rpm → ${interval.toFixed(2)} s between shots`,
      interval > 0 && def.magSize > 0 && def.reloadTime > 0);
  }
}
const modes = new Set(WEAPON_LIST.map((w) => w.mode));
check('semi, auto, burst, bolt, pump and melee modes are all represented',
  ['semi', 'auto', 'burst', 'bolt', 'pump', 'melee'].every((m) => modes.has(m)),
  [...modes].join(', '));

const resetStyles = new Set(WEAPON_LIST.map((w) => w.reloadStyle));
check('multiple reload styles exist (mag / cylinder / bolt / pump / none)',
  resetStyles.size >= 5, [...resetStyles].join(', '));

void WEAPONS;
console.log(`\n${checks - failures}/${checks} checks passed`);
if (failures) process.exit(1);
