/**
 * Headless verification of the retargeting pipeline.
 *
 * There is no browser in the build sandbox, so this script reproduces exactly
 * what the app does (parse GLB -> build bone tree -> prepareRetarget ->
 * bakeClip -> play the baked clip) and then forward-kinematics checks the
 * resulting pose heights. Run with:  npm run verify-rig
 */
import fs from 'node:fs';
import path from 'node:path';
import * as THREE from 'three';
import { prepareRetarget, bakeClip, findBone } from '../src/retarget.js';
import { CLIP_DEFS, CHARACTERS } from '../src/config.js';
import { loadRig, samplePose } from './lib/mini-gltf.mjs';

const ROOT = path.resolve(import.meta.dirname, '..');
const ANIM_DIR = path.join(ROOT, 'public/assets/animations');
const CHAR_DIR = path.join(ROOT, 'public/assets/characters');

const mean = (a) => a.reduce((s, v) => s + v, 0) / a.length;

const CLIPS = CLIP_DEFS.map((d) => d.id);
const sourceRigs = new Map();
for (const id of CLIPS) {
  const file = path.join(ANIM_DIR, `${id}.glb`);
  if (!fs.existsSync(file)) throw new Error(`missing clip asset: ${file}`);
  sourceRigs.set(id, loadRig(file));
}

let failures = 0;
for (const def of CHARACTERS) {
  const charFile = path.join(CHAR_DIR, path.basename(def.file));
  const char = loadRig(charFile);
  const hip = findBone(char.scene, 'mixamorig:Hips').getWorldPosition(new THREE.Vector3());
  console.log(`\n=== ${def.label} ===`);
  console.log(` rig: ${charFile.split('/').pop()}   bind-pose hips: ${hip.y.toFixed(2)} m   clips: ${CLIPS.length}`);

  // prepare every clip before baking any (baking animates the source rigs)
  const preps = new Map();
  for (const id of CLIPS) preps.set(id, prepareRetarget(sourceRigs.get(id).scene, char.scene, 'mixamorig:Hips'));
  console.log(` hip retarget scale: ${preps.get('idle').hipScale.toFixed(3)}`);

  for (const id of CLIPS) {
    const src = sourceRigs.get(id);
    const raw = samplePose(src.clips[0], src.scene);
    const baked = bakeClip(src.clips[0], preps.get(id), { fps: 30, inPlace: true });
    const pose = samplePose(baked, char.scene);

    const head = mean(pose.map((p) => p.headY));
    const hips = mean(pose.map((p) => p.hipsY));
    const expected = mean(raw.map((p) => p.headY)) * preps.get(id).hipScale;
    const ok = head > 0.02 && head < 2.4 && Math.abs(head - expected) < 0.6;
    if (!ok) failures += 1;
    console.log(
      ` ${ok ? 'ok  ' : 'FAIL'} ${id.padEnd(17)} head ${head.toFixed(2)} m (expect ~${expected.toFixed(2)})  hips ${hips.toFixed(2)} m  dur ${baked.duration.toFixed(2)} s  tracks ${baked.tracks.length}`,
    );
  }
}

console.log(failures === 0 ? '\nAll clips retargeted within tolerance.' : `\n${failures} clip(s) outside tolerance.`);
process.exit(failures === 0 ? 0 : 1);
