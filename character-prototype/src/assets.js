import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { CLIP_DEFS } from './config.js';

const loader = new GLTFLoader();

/** Loads a character GLB and returns the gltf object. */
export function loadCharacterGLTF(url) {
  return loader.loadAsync(url);
}

/**
 * Loads every animation clip GLB once. Each file is a bare Mixamo (X-Bot) skeleton
 * with a single motion clip; the clip is retargeted onto both characters later.
 *
 * @param {(loaded:number,total:number,name:string)=>void} [onProgress]
 * @returns {Promise<Map<string,{gltf: THREE.Group, clip: THREE.AnimationClip, def: object}>>}
 */
export async function loadSourceClips(onProgress) {
  const out = new Map();
  let loaded = 0;
  for (const def of CLIP_DEFS) {
    const url = `assets/animations/${def.id}.glb`;
    const gltf = await loader.loadAsync(url);
    const clip = gltf.animations[0];
    if (!clip) throw new Error(`Animation ${url} contains no clip`);
    clip.name = def.id;
    out.set(def.id, { gltf: gltf.scene, clip, def });
    loaded += 1;
    if (onProgress) onProgress(loaded, CLIP_DEFS.length, def.id);
  }
  return out;
}
