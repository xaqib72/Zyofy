import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { MeshoptDecoder } from 'three/addons/libs/meshopt_decoder.module.js';

/**
 * Real, downloaded weapon models.
 *
 * Every weapon in the catalogue points at a GLB in `public/assets/weapons/`
 * that came from a public, freely licensed pack (Quaternius "Ultimate Guns",
 * CC0; and three Sketchfab CC-BY models). Nothing here generates geometry - the
 * only work done in code is adapting each downloaded mesh to the game:
 *
 *   1. find its long axis and rotate it onto +Z (the game's "barrel forward"),
 *   2. scale it to the weapon's real-world length in metres,
 *   3. work out which end is the muzzle *from the geometry itself* - the muzzle
 *      end is the thin one, so both ends are profiled and the narrower end wins,
 *      and if that is the -Z end the model gets a half turn,
 *   4. shift it so the grip sits at the group origin, which is what
 *      `alignWeaponToHand` expects,
 *   5. expose a `muzzle` Object3D at the bore and any animated sub-parts that
 *      the model happens to name (the 870 MCS ships an `ActionBar` mesh, which
 *      becomes the pump).
 *
 * Because steps 1-4 are measured per model, a new GLB can be dropped in and it
 * will be oriented and scaled without hand tuning.
 */

// Some of the downloaded packs are EXT_meshopt_compressed; three ships the
// decoder, so they load like any other GLB.
const loader = new GLTFLoader();
loader.setMeshoptDecoder(MeshoptDecoder);

/** Every weapon GLB is fetched once and cloned per use. */
const cache = new Map();

export const WEAPON_ASSET_BASE = 'assets/weapons/';

/**
 * Where the hand sits along the weapon, as a fraction of its length measured
 * from the rear. Pistols are gripped right at the back, rifles a bit forward of
 * the stock.
 */
const GRIP_FRACTION = {
  pistol: 0.09,
  revolver: 0.12,
  smg: 0.3,
  rifle: 0.3,
  sniper: 0.32,
  shotgun: 0.28,
  lmg: 0.26,
  melee: 0.14,
};

/** Parts that a downloaded model may expose, by node-name fragment. */
const PART_MATCHERS = [
  ['pump', /actionbar|pump|forend|forestock/i],
  ['bolt', /bolt(?!_?handle_end)|bolthandle|slide_stop/i],
  ['mag', /^magazine$|magazine_(?!load)/i],
  ['slide', /^slide$/i],
];

function profileAlongZ(object, bins = 24) {
  const box = new THREE.Box3().setFromObject(object);
  const minZ = box.min.z;
  const maxZ = box.max.z;
  const span = Math.max(1e-6, maxZ - minZ);
  const heights = new Array(bins).fill(0);
  const widths = new Array(bins).fill(0);
  const centreY = new Array(bins).fill(0);
  const lowY = new Array(bins).fill(Infinity);
  const count = new Array(bins).fill(0);
  const v = new THREE.Vector3();

  object.updateMatrixWorld(true);
  object.traverse((child) => {
    if (!child.isMesh || !child.geometry?.attributes?.position) return;
    const pos = child.geometry.attributes.position;
    for (let i = 0; i < pos.count; i += 1) {
      v.fromBufferAttribute(pos, i).applyMatrix4(child.matrixWorld);
      const bin = Math.min(bins - 1, Math.max(0, Math.floor(((v.z - minZ) / span) * bins)));
      heights[bin] = Math.max(heights[bin], Math.abs(v.y - (box.min.y + box.max.y) / 2));
      widths[bin] = Math.max(widths[bin], Math.abs(v.x - (box.min.x + box.max.x) / 2));
      centreY[bin] += v.y;
      if (v.y < lowY[bin]) lowY[bin] = v.y;
      count[bin] += 1;
    }
  });
  for (let i = 0; i < bins; i += 1) if (count[i]) centreY[i] /= count[i];
  for (let i = 0; i < bins; i += 1) if (!Number.isFinite(lowY[i])) lowY[i] = box.max.y;
  return { box, heights, widths, centreY, lowY, count, minZ, maxZ, span, bins };
}


/**
 * Drops detached junk geometry.
 *
 * Some downloaded models ship extra copies or attachments parked far away from
 * the weapon body (the Remington 870 MCS, for instance, carries a second shell of
 * meshes about 60 units to the rear). Left in, they show up as a phantom second
 * gun next to the real one and they also wreck the bounding box that the
 * orientation/scale code measures.
 *
 * The body is identified as the largest contiguous run of occupied slabs along
 * the model's long axis, by vertex count; everything in a detached slab is
 * removed. A model whose meshes are all connected keeps every mesh.
 */
function pruneDetachedGeometry(scene) {
  scene.updateMatrixWorld(true);
  const meshes = [];
  scene.traverse((child) => {
    if (child.isMesh) {
      meshes.push({ mesh: child, bounds: new THREE.Box3().setFromObject(child) });
    }
  });
  if (meshes.length < 2) return { removed: 0, kept: meshes.length };

  // Union-find over touching bounding boxes (with a small margin, because the
  // pieces of a real weapon abut but rarely overlap).
  const parent = meshes.map((_, i) => i);
  const find = (i) => (parent[i] === i ? i : (parent[i] = find(parent[i])));
  const union = (a, b) => { parent[find(a)] = find(b); };
  for (let i = 0; i < meshes.length; i += 1) {
    for (let j = i + 1; j < meshes.length; j += 1) {
      const a = meshes[i].bounds.clone().expandByScalar(0.05);
      if (a.intersectsBox(meshes[j].bounds)) union(i, j);
    }
  }

  const groups = new Map();
  meshes.forEach((entry, i) => {
    const root = find(i);
    const list = groups.get(root) || [];
    list.push(entry);
    groups.set(root, list);
  });
  if (groups.size === 1) return { removed: 0, kept: meshes.length };

  // keep the connected group with the most geometry; drop the rest
  let bestList = null;
  let bestSize = -1;
  for (const list of groups.values()) {
    const total = list.reduce((sum, e) => sum + e.mesh.geometry.attributes.position.count, 0);
    if (total > bestSize) { bestSize = total; bestList = list; }
  }
  const keep = new Set(bestList.map((e) => e.mesh));
  let removed = 0;
  for (const entry of meshes) {
    if (!keep.has(entry.mesh)) {
      entry.mesh.parent?.remove(entry.mesh);
      removed += 1;
    }
  }
  scene.updateMatrixWorld(true);
  return { removed, kept: keep.size };
}

/** Rotates/scale/centres one loaded scene so it can be held like every other weapon. */
function adaptModel(scene, { length, category, flip }) {
  const pivot = new THREE.Group();
  pivot.name = 'weapon_asset';
  pivot.add(scene);
  const pruned = pruneDetachedGeometry(scene);

  // 1. long axis -> +Z
  let box = new THREE.Box3().setFromObject(scene);
  const size = box.getSize(new THREE.Vector3());
  if (size.x >= size.y && size.x >= size.z) {
    scene.rotation.y = -Math.PI / 2; // +X becomes +Z
  } else if (size.y >= size.x && size.y >= size.z) {
    scene.rotation.x = Math.PI / 2; // +Y becomes +Z
  }
  scene.updateMatrixWorld(true);

  // 2. scale to the real-world length
  box = new THREE.Box3().setFromObject(scene);
  const span0 = Math.max(1e-6, box.max.z - box.min.z);
  const k = length / span0;
  scene.scale.multiplyScalar(k);
  scene.updateMatrixWorld(true);

  // 3. Which end is the muzzle? Measure how far geometry *hangs below the bore*
  //    near each end: the grip, the trigger guard and the stock all sit under
  //    the bore line, while the barrel end is bare. The end with less material
  //    under the bore is the muzzle.
  let prof = profileAlongZ(scene);
  const boreY = (prof.centreY[0] + prof.centreY[prof.bins - 1]) / 2;
  const belowBore = (indices) => {
    let sum = 0;
    for (const i of indices) sum += Math.max(0, boreY - prof.lowY[i]);
    return sum;
  };
  const nearEnd = belowBore([0, 1, 2]);
  const farEnd = belowBore([prof.bins - 1, prof.bins - 2, prof.bins - 3]);
  // `flip` in the catalogue overrides the heuristic where a silhouette is
  // genuinely symmetric (a bayonet, a straight tube); every override was
  // checked against the rendered weapon sheet in scripts/render-views.mjs.
  const flipped = flip !== undefined ? flip : nearEnd < farEnd;
  if (flipped) {
    scene.rotation.y += Math.PI;
    scene.updateMatrixWorld(true);
    prof = profileAlongZ(scene);
  }

  // 4. grip at the origin: rear of the weapon sits at -grip*length
  const grip = (GRIP_FRACTION[category] ?? 0.3) * length;
  const centreZ = (prof.minZ + prof.maxZ) / 2;
  const rearZ = prof.minZ;
  const offsetZ = -rearZ - grip;
  const centreY = (prof.box.min.y + prof.box.max.y) / 2;
  scene.position.x += -centreZ + (prof.minZ + prof.maxZ) / 2; // keep x centred
  scene.position.z += offsetZ;
  scene.position.y -= centreY;
  scene.updateMatrixWorld(true);

  // 5. muzzle at the bore height of the last profile bin
  box = new THREE.Box3().setFromObject(scene);
  const muzzleY = prof.centreY[prof.bins - 1] - centreY;
  const muzzle = new THREE.Object3D();
  muzzle.name = 'muzzle';
  muzzle.position.set(0, Number.isFinite(muzzleY) ? muzzleY : 0, box.max.z);
  pivot.add(muzzle);

  // 6. collect named moving parts the model exposes
  const parts = {};
  scene.traverse((child) => {
    const name = child.name || '';
    for (const [key, re] of PART_MATCHERS) {
      if (!parts[key] && re.test(name) && child.isMesh) parts[key] = child;
    }
  });

  for (const mesh of []) void mesh;
  scene.traverse((child) => {
    if (child.isMesh) {
      child.castShadow = true;
      child.receiveShadow = false;
      child.frustumCulled = false;
      if (child.material) child.material.side = THREE.FrontSide;
    }
  });

  return {
    group: pivot,
    muzzle,
    parts,
    length,
    size: box.getSize(new THREE.Vector3()),
    measured: { nearEnd, farEnd, flipped, override: flip, prunedMeshes: pruned.removed },
  };
}

/** Adapts an already-parsed GLTF and makes it available to `buildWeapon`. */
export function cacheModel(def, gltf) {
  const adapted = adaptModel(gltf.scene, {
    length: def.modelLength,
    category: def.category,
    flip: def.flip,
  });
  cache.set(def.id, adapted);
  return adapted;
}

/** Loads one weapon GLB and adapts it. */
export async function loadWeaponModel(def) {
  const url = `${WEAPON_ASSET_BASE}${def.glb}`;
  const gltf = await loader.loadAsync(url);
  return cacheModel(def, gltf);
}

/**
 * Loads every catalogue entry that has a GLB. Returns `{ loaded, failed }` so the
 * caller can report exactly which downloaded assets did not make it in.
 */
export async function loadAllWeaponModels(defs, onProgress) {
  const loaded = [];
  const failed = [];
  for (let i = 0; i < defs.length; i += 1) {
    const def = defs[i];
    if (!def.glb) continue;
    try {
      await loadWeaponModel(def);
      loaded.push(def.id);
    } catch (error) {
      failed.push({ id: def.id, glb: def.glb, error: String(error && error.message) });
      console.error(`weapon model failed: ${def.glb}`, error);
    }
    if (onProgress) onProgress(i + 1, defs.length, def);
  }
  return { loaded, failed };
}

/** A fresh clone of an already-loaded weapon model, ready to mount. */
export function instantiateWeaponModel(def) {
  const template = cache.get(def.id);
  if (!template) return null;
  const group = template.group.clone(true);
  group.name = `weapon_${def.id}`;
  const muzzle = group.getObjectByName('muzzle') || new THREE.Object3D();
  const parts = {};
  for (const key of Object.keys(template.parts)) {
    const found = group.children
      .flatMap(function walk(o) { return [o, ...o.children.flatMap(walk)]; })
      .find((o) => o.isMesh && o.name === template.parts[key].name);
    if (found) parts[key] = found;
  }
  return {
    group,
    muzzle,
    parts,
    length: template.length,
    size: template.size.clone(),
    source: def.glb,
  };
}

export function hasWeaponModel(id) {
  return cache.has(id);
}

/** Test/debug helper: what is loaded right now. */
export function loadedWeaponModels() {
  return [...cache.entries()].map(([id, entry]) => ({
    id,
    length: Number(entry.length.toFixed(3)),
    size: entry.size.toArray().map((n) => Number(n.toFixed(3))),
    parts: Object.keys(entry.parts),
    flipped: entry.measured.flipped,
  }));
}
