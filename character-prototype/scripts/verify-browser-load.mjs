#!/usr/bin/env node
/**
 * Browser-faithful boot check.
 *
 * The other verify scripts parse GLBs with a hand-written reader. This one uses
 * the *real* `three/addons/loaders/GLTFLoader.js` that the page uses, so it
 * catches object-graph differences that a simplified reader can hide (for
 * example: GLTFLoader only instantiates `THREE.Bone` for nodes referenced as
 * skin joints — a bare animation skeleton loads as plain `Object3D`s, which is
 * exactly what broke the retargeter at boot).
 *
 * Node has no DOM/WebGL, so before parsing we strip image/texture/material data
 * from a copy of each GLB in memory. Geometry, skins, node hierarchy and
 * animations are untouched — everything the retargeter and mixer touch.
 *
 *   node scripts/verify-browser-load.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { prepareRetarget, bakeClip, findBone, rigNodes } from '../src/retarget.js';
import { CHARACTERS, CLIP_DEFS, BONE_NAMES } from '../src/config.js';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');

// GLTFLoader's default material path avoids the image loader; still, some
// three.js internals probe for DOM globals at module scope.
if (!globalThis.self) globalThis.self = globalThis;

/* ------------------------------------------------------- GLB read / rewrite */

function readGLB(file) {
  const buf = fs.readFileSync(file);
  let offset = 12;
  let json = null;
  let bin = null;
  while (offset < buf.length) {
    const length = buf.readUInt32LE(offset);
    const type = buf.readUInt32LE(offset + 4);
    const data = buf.subarray(offset + 8, offset + 8 + length);
    if (type === 0x4e4f534a) json = JSON.parse(data.toString('utf8'));
    else if (type === 0x004e4942) bin = Buffer.from(data);
    offset += 8 + length;
  }
  return { json, bin };
}

function writeGLB(json, bin) {
  const jsonBuf = Buffer.from(JSON.stringify(json), 'utf8');
  const jsonPad = Buffer.alloc((4 - (jsonBuf.length % 4)) % 4, 0x20);
  const jsonChunk = Buffer.concat([jsonBuf, jsonPad]);

  let binChunk = Buffer.alloc(0);
  if (bin) {
    const binPad = Buffer.alloc((4 - (bin.length % 4)) % 4, 0x00);
    binChunk = Buffer.concat([bin, binPad]);
  }

  const total = 12 + 8 + jsonChunk.length + (binChunk.length ? 8 + binChunk.length : 0);
  const out = Buffer.alloc(total);
  out.writeUInt32LE(0x46546c67, 0); // 'glTF'
  out.writeUInt32LE(2, 4);
  out.writeUInt32LE(total, 8);
  out.writeUInt32LE(jsonChunk.length, 12);
  out.writeUInt32LE(0x4e4f534a, 16); // 'JSON'
  jsonChunk.copy(out, 20);
  if (binChunk.length) {
    const at = 20 + jsonChunk.length;
    out.writeUInt32LE(binChunk.length, at);
    out.writeUInt32LE(0x004e4942, at + 4); // 'BIN'
    binChunk.copy(out, at + 8);
  }
  return out;
}

/** Removes everything that would need a DOM image decoder. */
function stripImages(json) {
  delete json.images;
  delete json.textures;
  delete json.samplers;
  for (const mesh of json.meshes || []) {
    for (const prim of mesh.primitives || []) {
      prim.material = undefined;
      delete prim.material;
    }
  }
  delete json.materials;
}

function parseGLB(file) {
  const { json, bin } = readGLB(file);
  stripImages(json);
  const buffer = writeGLB(json, bin);
  const loader = new GLTFLoader();
  return new Promise((resolve, reject) => {
    loader.parse(
      buffer.buffer.slice(buffer.byteOffset, buffer.byteOffset + buffer.byteLength),
      '',
      resolve,
      reject,
    );
  });
}

/* ------------------------------------------------------------------- checks */

const failures = [];
const check = (label, ok, detail = '') => {
  console.log(`${ok ? ' ok ' : 'FAIL'}  ${label}${detail ? `   ${detail}` : ''}`);
  if (!ok) failures.push(label);
};

const has = (root, name) => !!findBone(root, name);

async function main() {
  console.log('\n=== characters loaded exactly as the browser loads them ===');
  const rigs = [];
  for (const def of CHARACTERS) {
    const gltf = await parseGLB(path.join(ROOT, 'public/assets/characters', path.basename(def.file)));
    const scene = gltf.scene;
    scene.updateMatrixWorld(true);

    let bones = 0;
    let objects = 0;
    scene.traverse((o) => {
      if (o.isBone) bones += 1;
      else if (!o.isMesh) objects += 1;
    });
    rigs.push({ def, scene, bones });

    check(`${def.label}: skeleton loads with real Bone objects`, bones > 40, `${bones} bones`);
    check(`${def.label}: ${BONE_NAMES.hips} found`, has(scene, BONE_NAMES.hips));
    check(`${def.label}: head bone found`, has(scene, BONE_NAMES.head));
    check(`${def.label}: right hand bone found`, has(scene, BONE_NAMES.rightHand));

    const hips = findBone(scene, BONE_NAMES.hips);
    const hipsY = hips.getWorldPosition(new THREE.Vector3()).y;
    check(
      `${def.label}: bind-pose hips sit at a human height`,
      hipsY > 0.7 && hipsY < 1.4,
      `${hipsY.toFixed(2)} m`,
    );
  }

  // ---- animation skeletons: the case that used to throw at boot -------------
  console.log('\n=== animation skeletons (bare rigs, no skin) ===');
  const clipGltf = await parseGLB(path.join(ROOT, 'public/assets/animations/idle.glb'));
  const srcScene = clipGltf.scene;
  srcScene.updateMatrixWorld(true);

  let srcBones = 0;
  srcScene.traverse((o) => {
    if (o.isBone) srcBones += 1;
  });
  const joints = rigNodes(srcScene);
  console.log(`     GLTFLoader produced ${srcBones} THREE.Bone / ${joints.length} usable joints`);
  check('source rig exposes joints even without a skin', joints.length > 40, `${joints.length} joints`);
  check('source hips resolvable', has(srcScene, BONE_NAMES.hips));
  check('clip was loaded', clipGltf.animations.length > 0, `${clipGltf.animations.length} clip(s)`);

  // ---- the real prepareRetarget call from Character.prepareRetargeting ------
  console.log('\n=== prepareRetarget + bakeClip on the browser object graph ===');
  for (const { def, scene } of rigs) {
    const hip = findBone(scene, BONE_NAMES.hips);
    const restQ = hip.getWorldQuaternion(new THREE.Quaternion()).clone();
    let prep;
    try {
      prep = prepareRetarget(srcScene, scene, BONE_NAMES.hips);
      check(`${def.label}: prepareRetarget succeeds`, true, `${prep.entries.length} joints`);
    } catch (err) {
      check(`${def.label}: prepareRetarget succeeds`, false, err.message);
      continue;
    }
    const baked = bakeClip(clipGltf.animations[0], prep, { fps: 30 });
    check(
      `${def.label}: bakeClip produces a playable clip`,
      baked.tracks.length > 40 && baked.duration > 0.1,
      `${baked.tracks.length} tracks, ${baked.duration.toFixed(2)} s`,
    );

    // mixer binding: every target track name must resolve on the rig, otherwise
    // three.js silently warns and the character freezes in bind pose.
    const mixer = new THREE.AnimationMixer(scene);
    const action = mixer.clipAction(baked);
    const unresolved = baked.tracks.filter(
      (t) => THREE.PropertyBinding.findNode(scene, t.name.split('.')[0]) === undefined,
    );
    check(`${def.label}: every baked track binds to a real node`, unresolved.length === 0,
      unresolved.length ? unresolved.slice(0, 3).map((t) => t.name).join(', ') : '');
    action.play();
    mixer.setTime(baked.duration * 0.5);
    scene.updateMatrixWorld(true);
    const moved = hip.getWorldQuaternion(new THREE.Quaternion()).angleTo(restQ) * (180 / Math.PI);
    check(`${def.label}: hips actually move when the clip plays`, moved > 0.5, `${moved.toFixed(1)}°`);
    mixer.stopAllAction();
    mixer.uncacheClip(baked);
  }

  // ---- full sweep: every shipped clip, through the real loader, both rigs ----
  console.log('\n=== all clips: load -> prepareRetarget -> bakeClip -> mixer bind ===');
  const srcCache = new Map();
  srcCache.set('idle', clipGltf);
  for (const def of CLIP_DEFS) {
    if (srcCache.has(def.id)) continue;
    srcCache.set(
      def.id,
      await parseGLB(path.join(ROOT, 'public/assets/animations', `${def.id}.glb`)),
    );
  }
  for (const { def, scene } of rigs) {
    const bad = [];
    let baked = 0;
    for (const clipDef of CLIP_DEFS) {
      const gltf = srcCache.get(clipDef.id);
      const clip = gltf.animations && gltf.animations[0];
      if (!clip) {
        bad.push(`${clipDef.id}: no clip in file`);
        continue;
      }
      try {
        const prep = prepareRetarget(gltf.scene, scene, BONE_NAMES.hips);
        const out = bakeClip(clip, prep, { fps: 30 });
        const unresolved = out.tracks.filter(
          (t) => THREE.PropertyBinding.findNode(scene, t.name.split('.')[0]) === undefined,
        );
        if (unresolved.length) bad.push(`${clipDef.id}: unbound ${unresolved[0].name}`);
        else baked += 1;
      } catch (err) {
        bad.push(`${clipDef.id}: ${err.message}`);
      }
    }
    check(`${def.label}: all ${CLIP_DEFS.length} clips bake and bind`, bad.length === 0,
      bad.length ? bad.slice(0, 3).join(' | ') : `${baked} clips ok`);
  }

  console.log('\n=== summary ===');
  if (failures.length) {
    console.log(`${failures.length} check(s) failed:`);
    for (const f of failures) console.log(`  - ${f}`);
    process.exit(1);
  }
  console.log(`all checks passed (${CLIP_DEFS.length} clips defined)`);
}

main().catch((err) => {
  console.error('\nbrowser-load verification crashed:', err);
  process.exit(1);
});
