#!/usr/bin/env node
/**
 * Offline pose renderer - the substitute for a browser screenshot.
 *
 * There is no browser in this environment (no Chromium, playwright CDN
 * unreachable), so this script renders the *actual* skinned character meshes,
 * produced by the actual GLTFLoader + retargeter + controller, into PNG files
 * with a small software rasteriser. What you see is the real geometry the page
 * draws, not a diagram.
 *
 *   node scripts/render-poses.mjs                 all scenarios
 *   node scripts/render-poses.mjs male prone      filter by character/scenario
 *
 * Output: /home/user/renders/<character>-<scenario>.png
 */
import fs from 'node:fs';
import path from 'node:path';
import * as THREE from 'three';
import { Character } from '../src/character.js';
import { CharacterController } from '../src/controller.js';
import { CHARACTERS, CLIP_DEFS } from '../src/config.js';
import { findBone } from '../src/retarget.js';
import { parseGLB, skinnedVertex } from './lib/glb-browser.mjs';
import { Canvas, makeProjector } from './lib/raster.mjs';

const ROOT = path.resolve(import.meta.dirname, '..');
const OUT = '/home/user/renders';
const ANIM = path.join(ROOT, 'public/assets/animations');
const CHARS = path.join(ROOT, 'public/assets/characters');

const CELL_W = 300;
const CELL_H = 260;
const COLS = 5;
const SCALE = 150;

const filterChar = process.argv[2];
const filterScenario = process.argv[3];

/* ------------------------------------------------------------------- loading */

const sourceClips = new Map();
for (const def of CLIP_DEFS) {
  const gltf = await parseGLB(path.join(ANIM, `${def.id}.glb`));
  sourceClips.set(def.id, { gltf: gltf.scene, clip: gltf.animations[0], def });
}

async function buildCharacter(def) {
  const gltf = await parseGLB(path.join(CHARS, path.basename(def.file)));
  const character = new Character(def, gltf);
  character.prepareRetargeting(sourceClips);
  character.bakeMotion(sourceClips);
  // weapons are procedural primitives; they are part of the silhouette too
  const holder = new THREE.Object3D();
  character.setupWeapons(holder);
  return character;
}

/* -------------------------------------------------------------------- render */

/** Collects world-space triangles of every skinned mesh under `root`. */
function collectTriangles(root) {
  root.updateMatrixWorld(true);
  const tris = [];
  root.traverse((mesh) => {
    if (!mesh.isMesh || !mesh.geometry) return;
    if (mesh.isSkinnedMesh) mesh.skeleton.update();
    const geo = mesh.geometry;
    const pos = geo.attributes.position;
    const index = geo.index;
    const count = index ? index.count : pos.count;
    const isDark = /visor|eyes|weapon|rifle|pistol/i.test(mesh.name || '') || (mesh.parent && /weapon/i.test(mesh.parent.name || ''));
    for (let i = 0; i < count; i += 3) {
      const ia = index ? index.getX(i) : i;
      const ib = index ? index.getX(i + 1) : i + 1;
      const ic = index ? index.getX(i + 2) : i + 2;
      tris.push({
        a: skinnedVertex(mesh, ia, new THREE.Vector3()),
        b: skinnedVertex(mesh, ib, new THREE.Vector3()),
        c: skinnedVertex(mesh, ic, new THREE.Vector3()),
        dark: isDark,
      });
    }
  });
  return tris;
}

function renderCell(canvas, ox, oy, tris, view, center) {
  const project = (p) => makeProjector({
    width: CELL_W,
    height: CELL_H,
    view,
    center,
    scale: SCALE,
  })(p.x, p.y, p.z);

  // floor line at y = 0
  const floor = makeProjector({ width: CELL_W, height: CELL_H, view, center, scale: SCALE });
  const f = floor(0, 0, 0);

  const lightA = new THREE.Vector3(0.45, 0.8, 0.55).normalize();
  const lightB = new THREE.Vector3(-0.5, 0.35, -0.6).normalize();

  for (const t of tris) {
    const pa = project(t.a);
    const pb = project(t.b);
    const pc = project(t.c);
    // back-face cull in screen space (keep the pose readable, both sides shaded)
    const normal = new THREE.Vector3()
      .subVectors(t.b, t.a)
      .cross(new THREE.Vector3().subVectors(t.c, t.a))
      .normalize();
    const facing = normal.dot(new THREE.Vector3(0, 0, view === 'side' ? 1 : 1));
    const lambert = Math.abs(normal.dot(lightA)) * 0.75 + Math.abs(normal.dot(lightB)) * 0.25;
    const shade = 0.35 + 0.65 * lambert;
    const base = t.dark ? [60, 64, 72] : [196, 176, 148];
    const color = base.map((c) => Math.min(255, Math.round(c * shade)));
    if (facing < 0) color.forEach((v, i) => { color[i] = Math.round(v * 0.92); });
    // flat-shaded triangle with a screen-space z (depth) for the z-buffer
    canvas.triangle(
      [pa[0] + ox, pa[1] + oy, pa[2]],
      [pb[0] + ox, pb[1] + oy, pb[2]],
      [pc[0] + ox, pc[1] + oy, pc[2]],
      color,
    );
  }

  // ground line
  const y = Math.round(oy + f[1]);
  if (y >= 0 && y < canvas.height) {
    for (let x = ox; x < ox + CELL_W; x += 1) {
      const idx = y * canvas.width + x;
      if (canvas.depth[idx] > 5) {
        canvas.depth[idx] = 5;
        const i = idx * 4;
        canvas.rgba[i] = 60;
        canvas.rgba[i + 1] = 60;
        canvas.rgba[i + 2] = 60;
        canvas.rgba[i + 3] = 255;
      }
    }
  }
}

/* ------------------------------------------------------------------ scenarios */

/**
 * Each scenario: input script + which frames to capture. Frames are captured by
 * advancing the REAL controller and mixer.
 */
const SCENARIOS = {
  stand: { script: [], frames: [30, 45, 60, 75, 90] },
  walk: { script: [[45, ['KeyW', 'AltLeft']]], frames: [10, 20, 30, 40, 50] },
  prone_idle: { script: [[6, ['KeyZ'], true], [140, []]], frames: [20, 140, 160, 180, 200] },
  crawl_fwd: { script: [[6, ['KeyZ'], true], [140, []], [200, ['KeyW']]], frames: [20, 40, 60, 80, 100] },
  crawl_back: { script: [[6, ['KeyZ'], true], [140, []], [200, ['KeyS']]], frames: [20, 40, 60, 80, 100] },
  crawl_left: { script: [[6, ['KeyZ'], true], [140, []], [200, ['KeyA']]], frames: [20, 40, 60, 80, 100] },
  crawl_right: { script: [[6, ['KeyZ'], true], [140, []], [200, ['KeyD']]], frames: [20, 40, 60, 80, 100] },
  prone_up: { script: [[6, ['KeyZ'], true], [140, []], [6, ['KeyZ'], true]], frames: [10, 25, 45, 70, 100] },
  crouch: { script: [[6, ['ControlLeft'], true], [40, []]], frames: [5, 20, 40, 55, 70] },
  jump: { script: [[6, ['Space'], true], [30, []]], frames: [3, 10, 20, 30, 45] },
  aim_rifle: { script: [[30, [], false, true]], frames: [10, 20, 30, 40, 50] },
};

const makeInput = () => {
  const input = {
    keys: new Set(),
    pressed: new Set(),
    mouse: { dx: 0, dy: 0, left: false, leftPressed: false, right: false },
    isDown(c) { return this.keys.has(c); },
    wasPressed(c) { return this.pressed.has(c); },
    endFrame() { this.pressed.clear(); this.mouse.leftPressed = false; },
  };
  return input;
};

const camera = { yaw: Math.PI, pitch: 0.12 };

function step(character, controller, input) {
  controller.update(1 / 60, input, camera);
  character.update(1 / 60);
  character.group.updateMatrixWorld(true);
  input.endFrame();
}

async function runScenario(character, scenario) {
  const controller = new CharacterController(character, {});
  controller.setCameraProvider({ getAimOrigin: (v) => v.set(0, 1.5, 0) });
  const input = makeInput();

  // settle into idle first so every scenario starts from the same state
  for (let i = 0; i < 40; i += 1) step(character, controller, input);

  for (const [frames, keys, pressed, right] of scenario.script) {
    input.keys = new Set(keys || []);
    input.mouse.right = !!right;
    if (pressed) for (const k of keys) input.pressed.add(k);
    for (let i = 0; i < frames; i += 1) step(character, controller, input);
  }

  const captures = [];
  let last = 0;
  const total = Math.max(...scenario.frames);
  for (const want of scenario.frames) {
    for (let i = last; i < want; i += 1) step(character, controller, input);
    last = want;
    character.root.updateMatrixWorld(true);
    const snapshot = {
      label: `${character.currentId}`,
      tris: collectTriangles(character.group),
      hipsY: findBone(character.root, 'mixamorig:Hips').getWorldPosition(new THREE.Vector3()).y,
      headY: findBone(character.root, 'mixamorig:Head').getWorldPosition(new THREE.Vector3()).y,
      posture: controller.posture,
    };
    captures.push(snapshot);
  }
  void total;
  return captures;
}

/* ----------------------------------------------------------------------- main */

fs.mkdirSync(OUT, { recursive: true });
const results = [];

for (const def of CHARACTERS) {
  if (filterChar && filterChar !== def.id) continue;
  const character = await buildCharacter(def);

  for (const [name, scenario] of Object.entries(SCENARIOS)) {
    if (filterScenario && filterScenario !== name) continue;
    const captures = await runScenario(character, scenario);
    const rows = 1;
    const cols = captures.length;
    const canvas = new Canvas(cols * CELL_W, rows * CELL_H, [255, 255, 255]);

    captures.forEach((cap, i) => {
      // side view for locomotion, it shows posture best
      const view = name.startsWith('crawl') || name === 'prone_idle' || name === 'prone_up' ? 'side' : 'side';
      const center = [0, 0.55, 0];
      renderCell(canvas, i * CELL_W, 0, cap.tris, view, center);
    });

    const file = path.join(OUT, `${def.id}-${name}.png`);
    fs.writeFileSync(file, canvas.toPNG());
    results.push({
      file,
      char: def.id,
      scenario: name,
      clips: captures.map((c) => c.label),
      hipsY: captures.map((c) => +c.hipsY.toFixed(2)),
      headY: captures.map((c) => +c.headY.toFixed(2)),
      posture: captures.map((c) => c.posture),
    });
    console.log(`${def.id.padEnd(7)} ${name.padEnd(12)} ${file}  hips ${results.at(-1).hipsY.join(',')}  clips ${[...new Set(captures.map((c) => c.label))].join('/')}`);
  }
}

fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(results, null, 2));
console.log(`\nwrote ${results.length} renders to ${OUT}`);
