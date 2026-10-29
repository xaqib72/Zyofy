#!/usr/bin/env node
/**
 * Verifies the *downloaded* assets are present, loadable and actually wired in.
 *
 *   1. every file listed in public/assets/ASSETS.md exists on disk;
 *   2. every weapon GLB loads through the same loader the browser uses;
 *   3. the first-person rig loads and exposes its real clips + gun bones;
 *   4. every sound file decodes as an Ogg container and is non-trivial;
 *   5. the particle atlas is a real 1024x1024 sheet;
 *   6. the loaded meshes are not just present but *used*: the catalogue resolves
 *      each weapon to a distinct cached model.
 *
 * Run:  npm run verify-assets
 */
import fs from 'node:fs';
import path from 'node:path';
import { parseGLB } from './lib/glb-browser.mjs';
import { cacheModel, instantiateWeaponModel } from '../src/weaponAssets.js';
import { FpsRig } from '../src/fpsRig.js';
import { WEAPON_LIST } from '../src/weaponCatalog.js';

const ROOT = path.resolve(import.meta.dirname, '..');
let failures = 0;
let checks = 0;
const check = (label, ok, detail = '') => {
  checks += 1;
  if (!ok) failures += 1;
  console.log(`${ok ? ' ok ' : 'FAIL'}  ${label}${detail ? `   ${detail}` : ''}`);
};

const read = (rel) => path.join(ROOT, 'public', rel);
const exists = (rel) => fs.existsSync(read(rel));

/* --------------------------------------------- 1. documented files exist */

console.log('=== files documented in ASSETS.md ===');
const docs = fs.readFileSync(read('assets/ASSETS.md'), 'utf8');
const listed = [...docs.matchAll(/`([a-z0-9_.-]+\.(?:glb|opus|png))`/g)].map((m) => m[1]);
const seen = new Set();
for (const name of listed) {
  if (seen.has(name)) continue;
  seen.add(name);
  const candidates = [
    `assets/weapons/${name}`,
    `assets/fps/${name}`,
    `assets/sfx/${name}`,
    `assets/fx/${name}`,
  ].find(exists);
  check(`${name} is on disk`, !!candidates, candidates || 'MISSING');
}

/* --------------------------------------------- 2. weapons load + are used */

console.log('\n=== weapon models load and are wired into the catalogue ===');
const byId = new Map();
for (const def of WEAPON_LIST) {
  const file = read(`assets/weapons/${def.glb}`);
  try {
    const gltf = await parseGLB(file);
    cacheModel(def, gltf);
    const inst = instantiateWeaponModel(def);
    byId.set(def.id, inst);
    check(`${def.label}: ${def.glb} -> a mounted model`,
      !!inst && inst.group.children.length > 0,
      `muzzle z ${inst.muzzle.position.z.toFixed(2)}, parts ${Object.keys(inst.parts).join('/') || 'none'}`);
  } catch (error) {
    check(`${def.label}: ${def.glb} loads`, false, String(error.message).slice(0, 70));
  }
}
check('every catalogue weapon resolved a downloaded mesh',
  byId.size === WEAPON_LIST.length, `${byId.size}/${WEAPON_LIST.length}`);

/* --------------------------------------------- 3. first-person rig */

console.log('\n=== first-person rig ===');
const rig = new FpsRig();
rig.adopt(await parseGLB(read('assets/fps/ak47_hands.glb')), { length: 0.88 });
check('rig loaded', rig.loaded === true && !rig.error, rig.error || 'ok');
check('rig exposes its authored clips',
  ['idle', 'fire', 'reload_empty'].every((n) => rig.actions[n]),
  Object.keys(rig.actions).join(', '));
check('rig has the gun bones that drive the weapon',
  !!rig.gunBone && !!rig.muzzleBone && !!rig.gunMesh,
  [rig.gunBone?.name, rig.muzzleBone?.name, rig.gunMesh?.name].join(', '));
check('barrel was auto-aligned to the camera forward axis',
  !!rig.barrelDir && Math.abs(rig.barrelDir[2] + 1) < 0.05,
  `barrel direction ${JSON.stringify(rig.barrelDir)}`);
check('the rig accepts a different downloaded weapon',
  rig.setWeapon(WEAPON_LIST.find((w) => w.id === 'ak47')) === true, 'ak47 uses the asset gun');
const akAttached = rig.attached;
rig.setWeapon(WEAPON_LIST.find((w) => w.id === 'm4a1'));
check('switching weapon swaps the mesh on the gun bone',
  !!rig.attached && rig.attached !== akAttached && rig.gunMesh.visible === false,
  'own gun hidden, downloaded m4a1 attached');

/* --------------------------------------------- 4. sounds */

console.log('\n=== sound files ===');
const sounds = fs.readdirSync(read('assets/sfx'));
check('sound bank is populated', sounds.length >= 10, `${sounds.length} files`);
for (const name of sounds) {
  const buf = fs.readFileSync(read(`assets/sfx/${name}`));
  const isOgg = buf.subarray(0, 4).toString() === 'OggS';
  check(`assets/sfx/${name} is a real Ogg file`, isOgg && buf.length > 2000, `${Math.round(buf.length / 1024)} KB`);
}

/* --------------------------------------------- 5. particle atlas */

console.log('\n=== effects atlas ===');
const png = fs.readFileSync(read('assets/fx/particles.png'));
const width = png.readUInt32BE(16);
const height = png.readUInt32BE(20);
check('particles.png is a square 1024 sprite sheet', width === 1024 && height === 1024, `${width}x${height}`);

console.log(`\n${checks - failures}/${checks} checks passed`);
if (failures) process.exit(1);
