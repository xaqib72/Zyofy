/**
 * Fidelity check for the retargeting: compares limb directions of the
 * retargeted pose on each character against the original Mixamo clip.
 *
 * The two rigs are authored with different bind orientations (the Vanguard GLB
 * faces -Z, Michelle faces +Z) and different bone rolls, so directions are
 * compared after removing the rig's own rigid bind offset. Anything under ~10°
 * means the motion is transferred faithfully. Run with:  npm run verify-fidelity
 */
import path from 'node:path';
import * as THREE from 'three';
import { prepareRetarget, bakeClip, findBone } from '../src/retarget.js';
import { CHARACTERS } from '../src/config.js';
import { loadRig } from './lib/mini-gltf.mjs';

const ROOT = path.resolve(import.meta.dirname, '..');
const ANIM = (id) => path.join(ROOT, 'public/assets/animations', `${id}.glb`);
const CHAR = (file) => path.join(ROOT, 'public/assets/characters', path.basename(file));

const SEGMENTS = [
  ['mixamorig:LeftArm', 'mixamorig:LeftForeArm'],
  ['mixamorig:LeftForeArm', 'mixamorig:LeftHand'],
  ['mixamorig:RightArm', 'mixamorig:RightForeArm'],
  ['mixamorig:RightForeArm', 'mixamorig:RightHand'],
  ['mixamorig:LeftUpLeg', 'mixamorig:LeftLeg'],
  ['mixamorig:LeftLeg', 'mixamorig:LeftFoot'],
  ['mixamorig:RightUpLeg', 'mixamorig:RightLeg'],
  ['mixamorig:RightLeg', 'mixamorig:RightFoot'],
  ['mixamorig:Spine', 'mixamorig:Spine2'],
  ['mixamorig:Neck', 'mixamorig:Head'],
];

const CLIPS = ['idle', 'walk_fwd', 'run_fwd', 'crouch_idle', 'prone_idle', 'rifle_aim_idle', 'rifle_fire', 'jump_air', 'death_front'];

function segmentDirs(root) {
  root.updateMatrixWorld(true);
  return SEGMENTS.map(([a, b]) => findBone(root, b).getWorldPosition(new THREE.Vector3())
    .sub(findBone(root, a).getWorldPosition(new THREE.Vector3())).normalize());
}

function rigOffset(srcRoot, tgtRoot) {
  const s = findBone(srcRoot, 'mixamorig:Hips').getWorldQuaternion(new THREE.Quaternion());
  const t = findBone(tgtRoot, 'mixamorig:Hips').getWorldQuaternion(new THREE.Quaternion());
  return t.multiply(s.invert());
}

let worst = 0;
for (const def of CHARACTERS) {
  console.log(`\n=== ${def.label} ===`);
  for (const id of CLIPS) {
    const src = loadRig(ANIM(id));
    const tgt = loadRig(CHAR(def.file));
    // the app wraps rigs that are authored facing -Z in a 180° yaw before retargeting
    tgt.scene.rotation.y = def.modelYaw || 0;
    tgt.scene.updateMatrixWorld(true);
    const offsetInverse = rigOffset(src.scene, tgt.scene).invert();
    const prep = prepareRetarget(src.scene, tgt.scene, 'mixamorig:Hips');
    const baked = bakeClip(src.clips[0], prep, { fps: 30, inPlace: true });

    const srcMixer = new THREE.AnimationMixer(src.scene);
    const tgtMixer = new THREE.AnimationMixer(tgt.scene);
    srcMixer.clipAction(src.clips[0]).play();
    tgtMixer.clipAction(baked).play();

    let sum = 0;
    let max = 0;
    const samples = 5;
    for (let i = 0; i < samples; i++) {
      const t = (baked.duration * i) / samples * 0.95;
      srcMixer.setTime(t);
      tgtMixer.setTime(t);
      const s = segmentDirs(src.scene);
      const d = segmentDirs(tgt.scene);
      for (let k = 0; k < s.length; k++) {
        const err = d[k].clone().applyQuaternion(offsetInverse).angleTo(s[k]);
        sum += err;
        max = Math.max(max, err);
      }
    }
    const mean = (sum / (samples * SEGMENTS.length)) * (180 / Math.PI);
    const maxDeg = max * (180 / Math.PI);
    worst = Math.max(worst, mean);
    const ok = mean < 10;
    console.log(` ${ok ? 'ok  ' : 'FAIL'} ${id.padEnd(16)} mean limb-direction error ${mean.toFixed(1)}°   max ${maxDeg.toFixed(1)}°`);
  }
}

console.log(`\nworst clip mean error: ${worst.toFixed(1)}°`);
process.exit(worst < 10 ? 0 : 1);
