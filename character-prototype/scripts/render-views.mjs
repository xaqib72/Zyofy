#!/usr/bin/env node
/**
 * Renders what the app actually shows, without a browser.
 *
 * Uses the real characters, the real weapon models, the real shooting range and
 * the real controllers, then projects the resulting skinned geometry through a
 * perspective camera exactly like the page does, into PNG files.
 *
 *   node scripts/render-views.mjs
 *
 * Output: /home/user/renders/tps-range.png, fps-range.png, weapons.png
 */
import fs from 'node:fs';
import path from 'node:path';
import * as THREE from 'three';
import { Character } from '../src/character.js';
import { CharacterController } from '../src/controller.js';
import { CameraRig } from '../src/camera.js';
import { FpsRig } from '../src/fpsRig.js';
import { cacheModel } from '../src/weaponAssets.js';
import { ShootingRange } from '../src/range.js';
import { CHARACTERS, CLIP_DEFS } from '../src/config.js';
import { WEAPON_LIST, WEAPONS } from '../src/weaponCatalog.js';
import { buildWeapon } from '../src/weapons.js';
import { parseGLB, skinnedVertex } from './lib/glb-browser.mjs';
import { Canvas } from './lib/raster.mjs';

const ROOT = path.resolve(import.meta.dirname, '..');

/* --------------------------------------------------- real weapon models */

// The game preloads every weapon GLB before it builds the characters; do the same
// here so the renders show the downloaded meshes rather than the fallback.
for (const def of WEAPON_LIST) {
  const gltf = await parseGLB(path.join(ROOT, 'public/assets/weapons', def.glb));
  cacheModel(def, gltf);
}


const OUT = '/home/user/renders';
fs.mkdirSync(OUT, { recursive: true });

const W = 900;
const H = 560;

/* ------------------------------------------------------------------- assets */

const sourceClips = new Map();
for (const def of CLIP_DEFS) {
  const gltf = await parseGLB(path.join(ROOT, 'public/assets/animations', `${def.id}.glb`));
  sourceClips.set(def.id, { gltf: gltf.scene, clip: gltf.animations[0], def });
}

const gltf = await parseGLB(path.join(ROOT, 'public/assets/characters/vanguard_male.glb'));
const character = new Character(CHARACTERS[0], gltf);
character.prepareRetargeting(sourceClips);
character.bakeMotion(sourceClips);
const scene = new THREE.Scene();
scene.add(character.group);
const range = new ShootingRange(scene);

const camera = new THREE.PerspectiveCamera(62, W / H, 0.02, 400);
scene.add(camera);
const rig = new CameraRig(camera);

character.setupWeapons(new THREE.Scene());

// the same downloaded, rigged first-person asset the game uses
const fpsRig = new FpsRig();
fpsRig.gripOffset.set(0.16, -0.15, -0.70);
fpsRig.adopt(await parseGLB(path.join(ROOT, 'public/assets/fps/ak47_hands.glb')), { length: 0.88 });
fpsRig.placeForCamera();
fpsRig.refreshBase();
camera.add(fpsRig.group);

const input = {
  keys: new Set(),
  pressed: new Set(),
  mouse: { dx: 0, dy: 0, left: false, leftPressed: false, right: false, wheel: 0 },
  isDown(c) { return this.keys.has(c); },
  wasPressed(c) { return this.pressed.has(c); },
  endFrame() { this.pressed.clear(); this.mouse.leftPressed = false; this.mouse.wheel = 0; },
};

const controller = new CharacterController(character, {
  hitTest: (o, d, def) => range.hitTest(o, d, def),
  aimProbe: (o, d, m) => range.probe(o, d, m),
});
controller.setCameraProvider(rig);

/* ---------------------------------------------------------------- projection */

function perspective(width, height, cam) {
  const view = new THREE.Matrix4().copy(cam.matrixWorldInverse);
  const proj = new THREE.Matrix4().copy(cam.projectionMatrix);
  const m = new THREE.Matrix4().multiplyMatrices(proj, view);
  const fovScale = 1 / Math.tan((cam.fov * Math.PI) / 360);
  return (p) => {
    const v = new THREE.Vector4(p.x, p.y, p.z, 1).applyMatrix4(m);
    if (v.w <= 0.01) return null;
    return [
      (v.x / v.w * 0.5 + 0.5) * width,
      (1 - (v.y / v.w * 0.5 + 0.5)) * height,
      v.w,
    ];
  };
}

function collectTriangleStreams(root) {
  root.updateMatrixWorld(true);
  const out = [];
  root.traverse((mesh) => {
    if (!mesh.isMesh || !mesh.geometry) return;
    if (!mesh.visible) return;
    let parentVisible = true;
    let p = mesh;
    while (p) {
      if (p.visible === false) parentVisible = false;
      p = p.parent;
    }
    if (!parentVisible) return;
    if (mesh.isSkinnedMesh) mesh.skeleton.update();
    const geo = mesh.geometry;
    const pos = geo.attributes.position;
    const index = geo.index;
    const count = index ? index.count : pos.count;
    const dark = /weapon|knife|muzzle/i.test(mesh.name || '')
      || (mesh.parent && /weapon_/i.test(mesh.parent.name || ''));
    const base = dark ? [58, 62, 70] : [198, 178, 150];
    out.push({ mesh, pos, index, count, base });
    void skinnedVertex;
  });
  return out;
}

const LIGHT = new THREE.Vector3(0.4, 0.85, 0.35).normalize();

function renderScene(canvas, cam, root, background = [255, 255, 255]) {
  cam.updateMatrixWorld(true);
  cam.updateProjectionMatrix();
  cam.matrixWorldInverse.copy(cam.matrixWorld).invert();
  canvas.fill(background);
  canvas.depth.fill(Infinity);
  const project = perspective(canvas.width, canvas.height, cam);
  const streams = collectTriangleStreams(root);

  for (const s of streams) {
    const a = new THREE.Vector3();
    const b = new THREE.Vector3();
    const c = new THREE.Vector3();
    for (let i = 0; i < s.count; i += 3) {
      const ia = s.index ? s.index.getX(i) : i;
      const ib = s.index ? s.index.getX(i + 1) : i + 1;
      const ic = s.index ? s.index.getX(i + 2) : i + 2;
      if (s.mesh.isSkinnedMesh) {
        s.mesh.applyBoneTransform(ia, a.fromBufferAttribute(s.pos, ia)).applyMatrix4(s.mesh.matrixWorld);
        s.mesh.applyBoneTransform(ib, b.fromBufferAttribute(s.pos, ib)).applyMatrix4(s.mesh.matrixWorld);
        s.mesh.applyBoneTransform(ic, c.fromBufferAttribute(s.pos, ic)).applyMatrix4(s.mesh.matrixWorld);
      } else {
        a.fromBufferAttribute(s.pos, ia).applyMatrix4(s.mesh.matrixWorld);
        b.fromBufferAttribute(s.pos, ib).applyMatrix4(s.mesh.matrixWorld);
        c.fromBufferAttribute(s.pos, ic).applyMatrix4(s.mesh.matrixWorld);
      }
      const pa = project(a);
      const pb = project(b);
      const pc = project(c);
      if (!pa || !pb || !pc) continue;

      const normal = new THREE.Vector3().subVectors(b, a).cross(new THREE.Vector3().subVectors(c, a)).normalize();
      const lambert = Math.abs(normal.dot(LIGHT));
      const shade = 0.4 + 0.6 * lambert;
      const colour = s.base.map((v) => Math.min(255, Math.round(v * shade)));
      canvas.triangle(pa, pb, pc, colour);
    }
  }
}

function step(frames, setup) {
  if (setup) setup();
  for (let i = 0; i < frames; i += 1) {
    rig.update(1 / 60, input, character, controller);
    controller.update(1 / 60, input, rig);
    character.update(1 / 60);
    character.group.updateMatrixWorld(true);
    camera.updateMatrixWorld(true);
    character.group.visible = rig.mode !== 'fps';
    character.updateHandWeaponVisibility(rig.mode);
    const def = WEAPONS[controller.weapon];
    if (def) fpsRig.setWeapon(def);
    fpsRig.group.visible = rig.mode === 'fps';
    if (rig.mode === 'fps') fpsRig.update(1 / 60, { recoil: controller.recoilPitch || 0, aiming: controller.aiming });
    camera.updateMatrixWorld(true);
    input.endFrame();
  }
}

const hold = () => { input.keys = new Set(); input.mouse.left = false; input.mouse.right = false; };

/* ------------------------------------------------------------------ scenarios */

// Stand on the firing line, aiming down the range.
controller.position.set(0, 0, 6);
controller.equip('m4a1');
step(90, hold);
step(30, () => { hold(); input.mouse.right = true; });
rig.yaw = Math.PI;
rig.pitch = 0.03;
step(40, () => { hold(); input.mouse.right = true; });

// TPS
let canvas = new Canvas(W, H);
renderScene(canvas, camera, scene);
fs.writeFileSync(path.join(OUT, 'tps-range.png'), canvas.toPNG());
console.log('tps-range.png   camera', camera.position.toArray().map((n) => n.toFixed(2)).join(','));

// FPS
rig.toggle();
step(40, () => { hold(); input.mouse.right = true; });
canvas = new Canvas(W, H);
renderScene(canvas, camera, scene);
fs.writeFileSync(path.join(OUT, 'fps-range.png'), canvas.toPNG());
console.log('fps-range.png   camera', camera.position.toArray().map((n) => n.toFixed(2)).join(','),
  'near', camera.near);

/* ------------------------------------- close-ups of the weapon in the hands */

// Three quarter and side views of the same posed character, so the grip, the
// support hand and the barrel direction can actually be looked at.
const closeUps = [
  ['armed-idle', { yaw: 0.9, pitch: -0.15, dist: 3.0, height: 1.35, aim: false }],
  ['aim-side', { yaw: 1.62, pitch: -0.05, dist: 2.6, height: 1.45, aim: true }],
  ['prone-side', { yaw: 1.62, pitch: -0.28, dist: 2.4, height: 0.75, aim: false, prone: true }],
];
if (rig.mode !== 'tps') rig.toggle();
for (const [name, view] of closeUps) {
  hold();
  rig.yaw = Math.PI;
  rig.pitch = 0.05;
  controller.posture = 'stand';
  if (view.prone) {
    input.pressed.add('KeyZ');
    step(1, () => hold());
    input.pressed.clear();
    step(150, hold);
  } else {
    step(120, hold);
  }
  if (view.aim) step(30, () => { hold(); input.mouse.right = true; });
  const centre = character.group.position.clone().add(new THREE.Vector3(0, view.height, 0));
  const cam = new THREE.PerspectiveCamera(48, W / H, 0.02, 400);
  cam.position.copy(centre).add(new THREE.Vector3(
    Math.sin(view.yaw) * view.dist,
    Math.sin(view.pitch) * view.dist + view.height * 0.25,
    Math.cos(view.yaw) * view.dist,
  ));
  cam.lookAt(centre);
  canvas = new Canvas(W, H);
  renderScene(canvas, cam, scene);
  fs.writeFileSync(path.join(OUT, `r7-${name}.png`), canvas.toPNG());
  console.log(`r7-${name}.png   clip ${character.currentId}`);
}
hold();
controller.posture = 'stand';
step(120, hold);

/* ------------------------------------------------------------- weapon sheet */

const perRow = 6;
const cellW = 230;
const cellH = 130;
const rows = Math.ceil(WEAPON_LIST.length / perRow);
const sheet = new Canvas(perRow * cellW, rows * cellH + 24);
const sheetProject = (() => {
  const scale = 165;
  return (cx, cy, zoom) => (p) => [cx + p.z * scale * zoom, cy - p.y * scale * zoom, -p.x];
})();

for (let i = 0; i < WEAPON_LIST.length; i += 1) {
  const def = WEAPON_LIST[i];
  const built = buildWeapon(def);
  built.group.visible = true;
  built.group.updateMatrixWorld(true);
  const row = Math.floor(i / perRow);
  const col = i % perRow;
  const cx = col * cellW + cellW * 0.35;
  const cy = row * cellH + cellH * 0.62;
  const project = sheetProject(cx, cy, 0.9);

  built.group.traverse((mesh) => {
    if (!mesh.isMesh || !mesh.geometry) return;
    const geo = mesh.geometry;
    const pos = geo.attributes.position;
    const index = geo.index;
    const count = index ? index.count : pos.count;
    const a = new THREE.Vector3(); const b = new THREE.Vector3(); const c = new THREE.Vector3();
    for (let t = 0; t < count; t += 3) {
      const ia = index ? index.getX(t) : t;
      const ib = index ? index.getX(t + 1) : t + 1;
      const ic = index ? index.getX(t + 2) : t + 2;
      a.fromBufferAttribute(pos, ia).applyMatrix4(mesh.matrixWorld);
      b.fromBufferAttribute(pos, ib).applyMatrix4(mesh.matrixWorld);
      c.fromBufferAttribute(pos, ic).applyMatrix4(mesh.matrixWorld);
      const normal = new THREE.Vector3().subVectors(b, a).cross(new THREE.Vector3().subVectors(c, a)).normalize();
      const shade = 0.45 + 0.55 * Math.abs(normal.dot(new THREE.Vector3(0.3, 0.8, 0.4).normalize()));
      const mat = Array.isArray(mesh.material) ? mesh.material[0] : mesh.material;
      const colour = mat && mat.color
        ? [mat.color.r, mat.color.g, mat.color.b].map((c) => Math.round(Math.pow(c, 1 / 2.2) * 255))
        : [90, 94, 100];
      const shaded = colour.map((v) => Math.min(255, Math.round(v * shade)));
      sheet.triangle(project(a), project(b), project(c), shaded);
    }
  });
}
fs.writeFileSync(path.join(OUT, 'weapons.png'), sheet.toPNG());
console.log(`weapons.png     ${WEAPON_LIST.length} weapons, ${perRow} per row`);
