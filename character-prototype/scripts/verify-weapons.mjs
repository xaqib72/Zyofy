#!/usr/bin/env node
/**
 * Weapon system verification - against the *downloaded* models.
 *
 *  1. the catalogue points at 19 real GLB files that exist on disk, are all
 *     different files, and every one of them parses and adapts;
 *  2. the adapted geometry is genuinely different weapon to weapon (measured
 *     bounding box + vertex count), i.e. no renamed clones;
 *  3. the adapter's automatic muzzle detection picked the thin end of each model
 *     and the muzzle sits at the far (+Z) end;
 *  4. every weapon mounts on both rigs with the muzzle ahead of the hand;
 *  5. the mechanical parts that the assets themselves expose (the 870's Action
 *     Bar) move when their reload/cycle runs;
 *  6. the fire-mode / reload configuration of every weapon is coherent.
 *
 * Run:  npm run verify-weapons
 */
import fs from 'node:fs';
import path from 'node:path';
import * as THREE from 'three';
import { Character } from '../src/character.js';
import { CHARACTERS, CLIP_DEFS } from '../src/config.js';
import { WEAPON_LIST } from '../src/weaponCatalog.js';
import { buildWeapon } from '../src/weapons.js';
import { cacheModel } from '../src/weaponAssets.js';
import { parseGLB } from './lib/glb-browser.mjs';

const ROOT = path.resolve(import.meta.dirname, '..');
let failures = 0;
let checks = 0;
const check = (label, ok, detail = '') => {
  checks += 1;
  if (!ok) failures += 1;
  console.log(`${ok ? ' ok ' : 'FAIL'}  ${label}${detail ? `   ${detail}` : ''}`);
};

/* ------------------------------------------- 1. the downloaded files exist */

console.log('=== downloaded weapon assets ===');
const seen = new Map();
let missing = 0;
for (const def of WEAPON_LIST) {
  const file = path.join(ROOT, 'public/assets/weapons', def.glb);
  const exists = fs.existsSync(file);
  if (!exists) missing += 1;
  const size = exists ? fs.statSync(file).size : 0;
  if (seen.has(def.glb)) {
    check(`${def.label} uses its own mesh file`, false, `${def.glb} already used by ${seen.get(def.glb)}`);
  } else {
    seen.set(def.glb, def.label);
  }
  check(`${def.label}: ${def.glb} on disk`, exists && size > 1024, exists ? `${Math.round(size / 1024)} KB` : 'MISSING');
}
check('every catalogue entry has a distinct GLB', seen.size === WEAPON_LIST.length,
  `${seen.size}/${WEAPON_LIST.length} files, ${missing} missing`);

const categories = new Map();
for (const def of WEAPON_LIST) categories.set(def.category, (categories.get(def.category) || 0) + 1);
for (const cat of ['pistol', 'revolver', 'smg', 'rifle', 'sniper', 'shotgun', 'lmg', 'melee']) {
  check(`category "${cat}" present`, (categories.get(cat) || 0) > 0, `${categories.get(cat) || 0} weapons`);
}

/* --------------------------------------- 2. they parse, adapt and differ */

console.log('\n=== parsed + adapted geometry ===');
const signatures = new Map();
const adapted = [];
for (const def of WEAPON_LIST) {
  const file = path.join(ROOT, 'public/assets/weapons', def.glb);
  let entry = null;
  try {
    const gltf = await parseGLB(file);
    entry = cacheModel(def, gltf);
  } catch (error) {
    check(`${def.label}: parses and adapts`, false, String(error.message).slice(0, 80));
    continue;
  }
  const built = buildWeapon(def);
  built.group.updateMatrixWorld(true);
  const box = new THREE.Box3().setFromObject(built.group);
  const size = box.getSize(new THREE.Vector3());
  let verts = 0;
  let meshes = 0;
  built.group.traverse((o) => {
    if (o.isMesh) {
      meshes += 1;
      verts += o.geometry.attributes.position.count;
    }
  });
  const signature = `${size.x.toFixed(2)}x${size.y.toFixed(2)}x${size.z.toFixed(2)}|${verts}`;
  if (signatures.has(signature)) {
    check(`${def.label} is a distinct model`, false, `same geometry as ${signatures.get(signature)}`);
  } else {
    signatures.set(signature, def.label);
  }
  adapted.push({ def, built, size, verts, meshes, entry });

  check(`${def.label}: adapted length matches the catalogue (${def.modelLength} m)`,
    Math.abs(size.z - def.modelLength) / def.modelLength < 0.12,
    `measured ${size.z.toFixed(2)} m`);

  // the adapter profiles both ends and turns the thin end forward
  // After adaptation the +Z end must be the thin one, whichever way the source
  // file happened to be authored (that is what `flipped` records).
  const { nearEnd, farEnd, flipped } = entry.measured;
  check(`${def.label}: muzzle is the thin end of the mesh`, farEnd < nearEnd,
    `rear ${nearEnd.toFixed(1)} vs muzzle ${farEnd.toFixed(1)}${flipped ? ' (rotated in the loader)' : ''}`);

  check(`${def.label}: muzzle anchor is at the far end`,
    built.muzzle.position.z > size.z * 0.25,
    `muzzle z ${built.muzzle.position.z.toFixed(2)} of ${size.z.toFixed(2)}`);
}
check('all weapon models are geometrically distinct', signatures.size === WEAPON_LIST.length,
  `${signatures.size}/${WEAPON_LIST.length} unique meshes`);

const lengths = adapted.map((a) => a.size.z);
check('model lengths vary across the collection',
  Math.max(...lengths) / Math.min(...lengths) > 2.5,
  `${Math.min(...lengths).toFixed(2)} … ${Math.max(...lengths).toFixed(2)} m`);

/* ------------------------------------------------ 3. mounting on both rigs */

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
    else if (lateral > 0.45) bad.push(`${def.id}: muzzle off axis (${lateral.toFixed(2)} m)`);
    else if (muzzle.y < 0.35) bad.push(`${def.id}: muzzle too low (${muzzle.y.toFixed(2)} m)`);
  }
  check(`${charDef.label}: all ${WEAPON_LIST.length} weapons mount correctly`, bad.length === 0,
    bad.slice(0, 3).join(' | ') || 'muzzle ahead of hand, on the forward axis');

  /* ------------------------------------------- 4. asset-driven mechanics */

  const mechanicFailures = [];
  for (const def of WEAPON_LIST) {
    character.setWeapon(def.id);
    const weapon = character.weapons[def.id];
    const parts = Object.keys(weapon.parts);
    if (!parts.length) continue;
    if (def.reloadStyle === 'none') continue;
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
  check(`${charDef.label}: the assets' own mechanical parts animate`,
    mechanicFailures.length === 0, mechanicFailures.slice(0, 4).join(', ') || 'ok');
}

// the 870 MCS is the one downloaded model that ships separate mechanical pieces
const r870 = adapted.find((a) => a.def.id === 'r870');
check('Remington 870 MCS exposes its own Action Bar as the pump part',
  !!r870 && Object.keys(r870.entry.parts).includes('pump'),
  r870 ? `parts: ${Object.keys(r870.entry.parts).join(', ') || 'none'}` : 'missing');

/* ------------------------------------------------ 5. firing configuration */

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
  ['semi', 'auto', 'burst', 'bolt', 'pump', 'melee'].every((m) => modes.has(m)), [...modes].join(', '));

const styles = new Set(WEAPON_LIST.map((w) => w.reloadStyle));
check('multiple reload styles exist (mag / cylinder / bolt / pump / none)',
  styles.size >= 5, [...styles].join(', '));

check('scoped weapons are the scoped categories only',
  WEAPON_LIST.filter((w) => w.scope).every((w) => w.category === 'sniper'),
  WEAPON_LIST.filter((w) => w.scope).map((w) => w.label).join(', '));

console.log(`\n${checks - failures}/${checks} checks passed`);
if (failures) process.exit(1);
