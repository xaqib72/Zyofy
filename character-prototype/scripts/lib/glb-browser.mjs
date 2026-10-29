/**
 * Loads a GLB with the *real* `three/addons/loaders/GLTFLoader.js` inside Node.
 *
 * Node has no DOM/image decoder, so image + texture + material data is stripped
 * from an in-memory copy of the GLB before parsing. Geometry, skins, the node
 * hierarchy and animations - everything this project actually uses - are
 * untouched, so the resulting object graph is exactly what the browser builds.
 */
import fs from 'node:fs';
import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { MeshoptDecoder } from 'three/addons/libs/meshopt_decoder.module.js';

if (!globalThis.self) globalThis.self = globalThis;

export function readGLB(file) {
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

export function writeGLB(json, bin) {
  const jsonBuf = Buffer.from(JSON.stringify(json), 'utf8');
  const jsonPad = Buffer.alloc((4 - (jsonBuf.length % 4)) % 4, 0x20);
  const jsonChunk = Buffer.concat([jsonBuf, jsonPad]);
  const binPad = bin ? Buffer.alloc((4 - (bin.length % 4)) % 4, 0x00) : Buffer.alloc(0);
  const binChunk = bin ? Buffer.concat([bin, binPad]) : Buffer.alloc(0);

  const total = 12 + 8 + jsonChunk.length + (binChunk.length ? 8 + binChunk.length : 0);
  const out = Buffer.alloc(total);
  out.writeUInt32LE(0x46546c67, 0);
  out.writeUInt32LE(2, 4);
  out.writeUInt32LE(total, 8);
  out.writeUInt32LE(jsonChunk.length, 12);
  out.writeUInt32LE(0x4e4f534a, 16);
  jsonChunk.copy(out, 20);
  if (binChunk.length) {
    const at = 20 + jsonChunk.length;
    out.writeUInt32LE(binChunk.length, at);
    out.writeUInt32LE(0x004e4942, at + 4);
    binChunk.copy(out, at + 8);
  }
  return out;
}

function stripImages(json) {
  delete json.images;
  delete json.textures;
  delete json.samplers;
  for (const mesh of json.meshes || []) {
    for (const prim of mesh.primitives || []) delete prim.material;
  }
  delete json.materials;
}

export function parseGLB(file, { keepMaterials = false } = {}) {
  const { json, bin } = readGLB(file);
  if (!keepMaterials) stripImages(json);
  const buffer = writeGLB(json, bin);
  const loader = new GLTFLoader();
  loader.setMeshoptDecoder(MeshoptDecoder);
  return new Promise((resolve, reject) => {
    loader.parse(buffer.buffer.slice(buffer.byteOffset, buffer.byteOffset + buffer.byteLength), '', resolve, reject);
  });
}

/** World-space position of a skinned vertex. */
const _v = new THREE.Vector3();
export function skinnedVertex(mesh, index, out = new THREE.Vector3()) {
  if (!mesh.isSkinnedMesh) {
    return out.fromBufferAttribute(mesh.geometry.attributes.position, index).applyMatrix4(mesh.matrixWorld);
  }
  out.fromBufferAttribute(mesh.geometry.attributes.position, index);
  mesh.applyBoneTransform(index, out);
  return out.applyMatrix4(mesh.matrixWorld);
}

void _v;
