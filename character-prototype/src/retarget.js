import * as THREE from 'three';

/**
 * Minimal, dependency-free humanoid animation retargeter.
 *
 * Why this exists: the character meshes in this prototype (Mixamo "Vanguard"
 * and "Michelle") and the animation library (Mixamo clips authored on the
 * X-Bot rig) are *all* Mixamo rigs with identical bone names, but their bind
 * poses are stored with different local bone axes and different unit scales
 * (X-Bot: metres, the character GLBs: centimetres under a 0.01 root scale).
 * A naive "copy the quaternion track" transfer therefore produces broken
 * poses. Instead we transfer the *world-space rotation delta from the bind
 * pose*, which is rig-agnostic, and resample the result at a fixed frame rate.
 *
 * For the hips we transfer the vertical component of the root motion only
 * (horizontal root motion is stripped: the prototype's controller drives the
 * character, so the clips must be in place).
 */

const _srcQ = new THREE.Quaternion();
const _boneOffset = new THREE.Quaternion();
const _worldQ = new THREE.Quaternion();
const _localQ = new THREE.Quaternion();
const _parentQInv = new THREE.Quaternion();
const _v = new THREE.Vector3();
const _v2 = new THREE.Vector3();
const _m = new THREE.Matrix4();

const isMeshNode = (o) => o.isMesh || o.isSkinnedMesh || o.isLine || o.isPoints || o.isLight;

/**
 * Skeleton nodes of a rig, in hierarchy order.
 *
 * GLTFLoader only creates `THREE.Bone` objects for nodes that are referenced as
 * skin joints. The animation GLBs in this project are *bare skeletons* (no
 * skinned mesh, no `skins` array), so every joint loads as a plain
 * `THREE.Object3D`. Restricting traversal to `isBone` therefore made the source
 * rig look empty. When a rig exposes no bones we fall back to every non-mesh
 * node in the hierarchy, which is exactly the joint list in both cases.
 */
export function rigNodes(root) {
  const bones = [];
  root.traverse((o) => {
    if (o.isBone) bones.push(o);
  });
  if (bones.length) return bones;
  const nodes = [];
  root.traverse((o) => {
    if (o !== root && !isMeshNode(o)) nodes.push(o);
  });
  return nodes;
}

function collectBones(root) {
  const map = new Map();
  for (const node of rigNodes(root)) map.set(normalizeBoneName(node.name), node);
  return map;
}

/** Bone names can be sanitized by the GLTF loader (e.g. "mixamorig:Hips" -> "mixamorigHips"). 
 * Compare on a normalized form so lookups never depend on that. */
export function normalizeBoneName(name) {
  return String(name).toLowerCase().replace(/[^a-z0-9]/g, '');
}

export function findBone(root, wanted) {
  const target = normalizeBoneName(wanted);
  let found = null;
  root.traverse((o) => {
    if (found || isMeshNode(o)) return;
    if (normalizeBoneName(o.name) === target) found = o;
  });
  return found;
}

function worldQuaternions(root, bones) {
  root.updateMatrixWorld(true);
  const out = new Map();
  for (const [name, bone] of bones) {
    out.set(name, bone.getWorldQuaternion(new THREE.Quaternion()));
  }
  return out;
}

/**
 * @typedef {Object} RetargetPrep
 * @property {THREE.Object3D} sourceRoot
 * @property {THREE.Object3D} targetRoot
 * @property {Map<string,THREE.Bone>} sourceBones
 * @property {Array<{name:string,parent:number,restQ:THREE.Quaternion,restWorldQ:THREE.Quaternion}>} entries
 * @property {Map<string,THREE.Quaternion>} sourceRestQ
 * @property {number} hipIndex
 * @property {THREE.Vector3} hipRestWorld
 * @property {THREE.Matrix4} hipParentRestWorld
 * @property {number} hipScale
 */

/**
 * Pre-computes everything that does not change between clips.
 * @param {THREE.Object3D} sourceRoot rig that owns the animation clips (bind pose!)
 * @param {THREE.Object3D} targetRoot character to animate (bind pose!)
 * @param {string} hipBoneName
 */
export function prepareRetarget(sourceRoot, targetRoot, hipBoneName = 'mixamorig:Hips') {
  const sourceBones = collectBones(sourceRoot);
  const sourceRestQ = worldQuaternions(sourceRoot, sourceBones);
  const sourceRestQInv = new Map();
  for (const [name, q] of sourceRestQ) sourceRestQInv.set(name, q.clone().invert());


  targetRoot.updateMatrixWorld(true);

  const entries = [];
  const indexByName = new Map();

  // Hierarchy-ordered list of target joints (rigNodes is parents-first).
  for (const node of rigNodes(targetRoot)) {
    // nearest ancestor joint, if any
    let parent = node.parent;
    let parentIndex = -1;
    while (parent) {
      const parentKey = normalizeBoneName(parent.name);
      if (indexByName.has(parentKey)) {
        parentIndex = indexByName.get(parentKey);
        break;
      }
      parent = parent.parent;
    }
    const sourceBone = sourceBones.get(normalizeBoneName(node.name));
    entries.push({
      name: node.name,
      bone: node,
      // name sanitisation differs between loaders, so always compare normalised
      sourceBone: sourceBone || null,
      parent: parentIndex,
      restQ: node.quaternion.clone(),
      restWorldQ: node.getWorldQuaternion(new THREE.Quaternion()),
      // constant local-frame offset between the two bind poses: the standard
      // "rest offset" used when transferring joint rotations between rigs
      localOffset: sourceBone
        ? node.quaternion.clone().multiply(sourceBone.quaternion.clone().invert())
        : new THREE.Quaternion(),
      // world rotation of the nearest non-joint ancestor (constant, bind pose)
      parentRestWorldQ: node.parent ? node.parent.getWorldQuaternion(new THREE.Quaternion()) : new THREE.Quaternion(),
      hasSource: !!sourceBone,
      // inverse bind-pose world rotation of the matching source joint
      sourceRestQInv: sourceBone
        ? sourceRestQInv.get(normalizeBoneName(sourceBone.name))
        : null,
    });
    indexByName.set(normalizeBoneName(node.name), entries.length - 1);
  }
  if (!entries.length) {
    throw new Error('Retarget: target rig exposes no skeleton nodes');
  }

  const hipBone = findBone(targetRoot, hipBoneName);
  const sourceHip = findBone(sourceRoot, hipBoneName);
  if (!hipBone || !sourceHip) {
    const names = (root) => rigNodes(root).slice(0, 8).map((n) => n.name).join(', ') || '(none)';
    throw new Error(
      `Retarget: hip joint "${hipBoneName}" missing on ${!hipBone ? 'target' : 'source'} rig ` +
        `(target joints: ${names(targetRoot)} | source joints: ${names(sourceRoot)})`,
    );
  }
  const resolvedHipName = hipBone.name;

  const rigOffset = hipBone.getWorldQuaternion(new THREE.Quaternion())
    .multiply(sourceHip.getWorldQuaternion(new THREE.Quaternion()).invert());

  const hipRestWorld = hipBone.getWorldPosition(new THREE.Vector3());
  const sourceHipRestWorld = sourceHip.getWorldPosition(new THREE.Vector3());
  const hipParentRestWorld = hipBone.parent.matrixWorld.clone();

  return {
    sourceRoot,
    targetRoot,
    sourceBones,
    entries,
    sourceRestQ,
    sourceRestQInv,
    hipIndex: indexByName.get(normalizeBoneName(resolvedHipName)),
    hipName: resolvedHipName,
    hipParentRestWorld,
    hipScale: hipRestWorld.y / (sourceHipRestWorld.y || 1),
    rigOffset,
    rigOffsetInv: rigOffset.clone().invert(),
    debug: { hipRestWorld, sourceHipRestWorld },
  };
}

/**
 * Bakes one source clip onto the target rig.
 * @param {THREE.AnimationClip} clip source clip (X-Bot / Mixamo rig)
 * @param {ReturnType<typeof prepareRetarget>} prep
 * @param {Object} [options]
 * @param {number} [options.fps] sampling rate, default 30
 * @param {boolean} [options.inPlace] strip horizontal root motion (default true)
 * @param {number} [options.trimStart] seconds to trim from the start
 * @param {number} [options.trimEnd] seconds to trim from the end
 * @param {number} [options.hipYOffset] small constant offset added to the hips height
 * @returns {THREE.AnimationClip}
 */
export function bakeClip(clip, prep, options = {}) {
  const {
    fps = 30,
    inPlace = true,
    trimStart = 0,
    trimEnd = 0,
    hipYOffset = 0,
    method = 'world',
  } = options;

  const { sourceRoot, entries, sourceRestQInv, sourceBones, hipIndex, hipParentRestWorld, hipScale, hipName } = prep;

  const duration = Math.max(0.001, clip.duration - trimStart - trimEnd);
  const frameCount = Math.max(2, Math.round(duration * fps) + 1);
  const dt = duration / (frameCount - 1);

  const mixer = new THREE.AnimationMixer(sourceRoot);
  const action = mixer.clipAction(clip, sourceRoot);
  action.reset();
  action.play();

  const quatValues = new Map(); // name -> Float32Array
  for (const e of entries) {
    if (e.hasSource) quatValues.set(e.name, new Float32Array(frameCount * 4));
  }
  const hipPositions = new Float32Array(frameCount * 3);

  // world quaternions of the *animated* target skeleton, rebuilt per frame
  const frameWorldQ = new Array(entries.length).fill(null).map(() => new THREE.Quaternion());

  const sourceHipBone = findBone(sourceRoot, hipName);
  const sourceHipRestWorld = sourceHipBone.getWorldPosition(new THREE.Vector3());
  const targetHipRestLocal = entries[hipIndex].bone.position.clone();

  for (let f = 0; f < frameCount; f++) {
    const t = trimStart + f * dt;
    mixer.setTime(t);
    sourceRoot.updateMatrixWorld(true);

    // ---- rotations ----
    for (let i = 0; i < entries.length; i++) {
      const e = entries[i];
      const parentQ = e.parent >= 0 ? frameWorldQ[e.parent] : e.parentRestWorldQ;

      if (!e.hasSource) {
        // untouched bone: keep bind pose and propagate its rest world rotation
        frameWorldQ[i].copy(parentQ).multiply(e.restQ);
        continue;
      }

      const srcBone = e.sourceBone;

      if (method === 'local') {
        // Transfer the source joint's *local* rotation, offset by the bind-pose
        // frame difference. Preserves the pose exactly and keeps every chain
        // (arm, leg, spine) intact regardless of how each rig was authored.
        _localQ.copy(e.localOffset).multiply(srcBone.quaternion).normalize();
        _localQ.toArray(quatValues.get(e.name), f * 4);
        frameWorldQ[i].copy(parentQ).multiply(_localQ);
        continue;
      }

      // global-delta transfer: take the bone's world rotation delta from the
      // source bind pose, re-express it in the target rig's frame (rigOffset) and
      // apply it to the target's bind pose. Directions then match the source
      // exactly while each rig keeps its own bone rolls / bind orientation.
      srcBone.getWorldQuaternion(_srcQ);
      _boneOffset.copy(_srcQ).multiply(e.sourceRestQInv);   // world-space delta from the source bind pose
      _worldQ.copy(prep.rigOffset).multiply(_boneOffset).multiply(prep.rigOffsetInv).multiply(e.restWorldQ);
      frameWorldQ[i].copy(_worldQ);
      _parentQInv.copy(parentQ).invert();
      _localQ.copy(_parentQInv).multiply(_worldQ).normalize();
      _localQ.toArray(quatValues.get(e.name), f * 4);
    }

    // ---- hips position (vertical only, scaled to the target's proportions) ----
    sourceHipBone.getWorldPosition(_v);
    _v2.copy(_v).sub(sourceHipRestWorld).multiplyScalar(hipScale);
    if (inPlace) {
      _v2.x = 0;
      _v2.z = 0;
    }
    _v2.y += hipYOffset;
    _v2.add(prep.debug.hipRestWorld);
    _m.copy(hipParentRestWorld).invert();
    _v2.applyMatrix4(_m);
    _v2.toArray(hipPositions, f * 3);
  }

  mixer.uncacheClip(clip);

  const tracks = [];
  const times = new Float32Array(frameCount);
  for (let f = 0; f < frameCount; f++) times[f] = f * dt;

  for (const [name, values] of quatValues) {
    // 4-component quaternion track, interpolated with slerp by three.js
    tracks.push(new THREE.QuaternionKeyframeTrack(`${name}.quaternion`, times, values));
  }
  tracks.push(new THREE.VectorKeyframeTrack(`${hipName}.position`, times, hipPositions));

  const baked = new THREE.AnimationClip(clip.name, duration, tracks);
  baked.optimize();
  return baked;
}

/**
 * Convenience: prepare once, bake many.
 */
export function createRetargeter({ sourceRoot, targetRoot, hipBoneName, fps = 30, inPlace = true }) {
  const prep = prepareRetarget(sourceRoot, targetRoot, hipBoneName);
  return {
    prep,
    bake(clip, options = {}) {
      return bakeClip(clip, prep, { fps, inPlace, ...options });
    },
  };
}
