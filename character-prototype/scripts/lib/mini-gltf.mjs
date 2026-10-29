import fs from 'node:fs';
import * as THREE from 'three';
import { findBone } from '../../src/retarget.js';

/**
 * Very small GLB reader used by the headless verification scripts.
 * It builds the same bone hierarchy + AnimationClips that GLTFLoader produces
 * in the browser (including GLTFLoader's node-name sanitisation), so the
 * retargeting / animation logic can be exercised without a browser.
 */
export function readGLB(file) {
  const buf = fs.readFileSync(file);
  const total = buf.readUInt32LE(8);
  let offset = 12;
  let json = null;
  let bin = null;
  while (offset < total) {
    const chunkLength = buf.readUInt32LE(offset);
    const chunkType = buf.readUInt32LE(offset + 4);
    const data = buf.subarray(offset + 8, offset + 8 + chunkLength);
    if (chunkType === 0x4e4f534a) json = JSON.parse(data.toString('utf8'));
    else bin = data;
    offset += 8 + chunkLength;
  }
  return { json, bin };
}

const COMPONENT = {
  5120: [Int8Array, 1],
  5121: [Uint8Array, 1],
  5122: [Int16Array, 2],
  5123: [Uint16Array, 2],
  5125: [Uint32Array, 4],
  5126: [Float32Array, 4],
};
const NUM_COMPONENTS = { SCALAR: 1, VEC2: 2, VEC3: 3, VEC4: 4, MAT4: 16 };

function readAccessor(json, bin, index) {
  const acc = json.accessors[index];
  const view = json.bufferViews[acc.bufferView];
  const [Arr] = COMPONENT[acc.componentType];
  const components = NUM_COMPONENTS[acc.type];
  const byteOffset = (view.byteOffset || 0) + (acc.byteOffset || 0);
  const array = new Arr(bin.buffer, bin.byteOffset + byteOffset, acc.count * components);
  return { array, components, count: acc.count };
}

/** Minimal GLB -> THREE.Object3D (bones only) + AnimationClip builder. */
export function loadRig(file) {
  const { json, bin } = readGLB(file);
  // GLTFLoader only instantiates THREE.Bone for nodes that are skin joints; a
  // bare animation skeleton (no `skins` array) loads as plain Object3Ds. Mirror
  // that exactly, otherwise the headless tests hide browser-only failures.
  const joints = new Set();
  for (const skin of json.skins || []) for (const j of skin.joints) joints.add(j);
  const objects = json.nodes.map((node, i) => {
    const obj = joints.has(i) ? new THREE.Bone() : new THREE.Object3D();
    // GLTFLoader sanitizes node names (":" and other reserved chars are stripped),
    // so replicate that here to keep track binding identical to the browser.
    obj.name = (node.name || `node_${i}`).replace(/\s/g, '_').replace(/[\[\]\.:\/]/g, '');
    if (node.translation) obj.position.fromArray(node.translation);
    if (node.rotation) obj.quaternion.fromArray(node.rotation);
    if (node.scale) obj.scale.fromArray(node.scale);
    return obj;
  });
  json.nodes.forEach((node, i) => {
    (node.children || []).forEach((child) => objects[i].add(objects[child]));
  });
  const scene = new THREE.Object3D();
  scene.name = 'scene';
  const roots = json.scenes[json.scene || 0].nodes;
  roots.forEach((r) => scene.add(objects[r]));
  scene.updateMatrixWorld(true);

  const clips = (json.animations || []).map((anim) => {
    const tracks = [];
    let duration = 0;
    for (const channel of anim.channels) {
      const sampler = anim.samplers[channel.sampler];
      const times = readAccessor(json, bin, sampler.input);
      const values = readAccessor(json, bin, sampler.output);
      duration = Math.max(duration, times.array[times.count - 1]);
      const nodeName = objects[channel.target.node].name;
      const t = Array.from(times.array);
      const v = Array.from(values.array);
      if (channel.target.path === 'rotation') {
        tracks.push(new THREE.QuaternionKeyframeTrack(`${nodeName}.quaternion`, t, v));
      } else if (channel.target.path === 'translation') {
        tracks.push(new THREE.VectorKeyframeTrack(`${nodeName}.position`, t, v));
      }
    }
    return new THREE.AnimationClip(anim.name || 'clip', duration, tracks);
  });

  return { scene, clips };
}

export function samplePose(clip, scene) {
  const mixer = new THREE.AnimationMixer(scene);
  const action = mixer.clipAction(clip);
  action.play();
  const head = findBone(scene, 'mixamorig:Head');
  const hips = findBone(scene, 'mixamorig:Hips');
  const out = [];
  const steps = 8;
  for (let i = 0; i < steps; i++) {
    mixer.setTime((clip.duration * i) / (steps - 1) * 0.999);
    scene.updateMatrixWorld(true);
    out.push({
      headY: head.getWorldPosition(new THREE.Vector3()).y,
      hipsY: hips.getWorldPosition(new THREE.Vector3()).y,
      headZ: head.getWorldPosition(new THREE.Vector3()).z,
    });
  }
  mixer.uncacheClip(clip);
  return out;
}

const mean = (a) => a.reduce((s, v) => s + v, 0) / a.length;

function inspectGlb(file) {
  const { json } = readGLB(file);
  const scale = { min: Infinity, max: -Infinity };
  const hips = json.nodes.find((n) => /Hips$/.test(n.name || ''));
  return { animationName: json.animations?.[0]?.name, hipNodeY: hips?.translation?.[1] };
}

