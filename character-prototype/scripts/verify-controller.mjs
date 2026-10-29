/**
 * Headless smoke test of the movement / animation state machine.
 *
 * Builds the real Character + CharacterController objects from the shipped
 * GLB assets, feeds them synthetic input frames and asserts that every state
 * resolves to a clip that actually exists. Run with:  npm run verify-controls
 */
import path from 'node:path';
import * as THREE from 'three';
import { Character } from '../src/character.js';
import { CharacterController } from '../src/controller.js';
import { CHARACTERS, CLIP_DEFS } from '../src/config.js';
import { loadRig } from './lib/mini-gltf.mjs';

const ROOT = path.resolve(import.meta.dirname, '..');
const ANIM_DIR = path.join(ROOT, 'public/assets/animations');
const CHAR_DIR = path.join(ROOT, 'public/assets/characters');

const sourceClips = new Map();
for (const def of CLIP_DEFS) {
  const rig = loadRig(path.join(ANIM_DIR, `${def.id}.glb`));
  sourceClips.set(def.id, { gltf: rig.scene, clip: rig.clips[0], def });
}

const characters = CHARACTERS.map((def) => new Character(def, loadRig(path.join(CHAR_DIR, path.basename(def.file)))));
for (const c of characters) c.prepareRetargeting(sourceClips);
for (const c of characters) c.bakeMotion(sourceClips);
for (const c of characters) c.setupWeapons(new THREE.Scene());

const controller = new CharacterController(characters[0], {});

/** tiny stand-in for the browser Input class */
const input = {
  keys: new Set(),
  pressed: new Set(),
  mouse: { dx: 0, dy: 0, left: false, right: false },
  isDown(c) { return this.keys.has(c); },
  wasPressed(c) { return this.pressed.has(c); },
  endFrame() { this.pressed.clear(); },
};

const camera = { yaw: Math.PI, pitch: 0.15 };
const seen = new Set();
const missing = [];

function simulate(frames, setup, label) {
  setup();
  for (let i = 0; i < frames; i++) {
    controller.update(1 / 60, input, camera);
    characters[0].update(1 / 60);
    const clip = characters[0].currentId;
    if (!clip) missing.push(`${label}: no clip active`);
    else {
      seen.add(clip);
      if (!characters[0].actions.has(clip)) missing.push(`${label}: unknown clip ${clip}`);
    }
    input.endFrame();
  }
  console.log(` ${label.padEnd(28)} -> ${characters[0].currentId}`);
}

// every step starts from a neutral mouse/touch state unless it sets buttons itself
const hold = (codes) => {
  input.keys = new Set(codes);
  input.mouse.left = false;
  input.mouse.right = false;
};

console.log('=== state machine walkthrough (male character) ===');
simulate(60, () => hold([]), 'idle');
simulate(40, () => hold(['KeyW', 'AltLeft']), 'walk forward (Alt)');
simulate(40, () => hold(['KeyW']), 'run forward');
simulate(40, () => hold(['KeyW', 'ShiftLeft']), 'sprint');
simulate(40, () => hold(['KeyS']), 'run backward');
simulate(40, () => hold(['KeyA']), 'strafe left');
simulate(40, () => hold(['KeyD', 'AltLeft']), 'strafe right (walk)');
simulate(40, () => hold(['KeyD']), 'strafe right (run)');
simulate(20, () => { hold([]); input.pressed.add('Space'); }, 'jump');
simulate(110, () => hold([]), 'airborne / land');
simulate(20, () => { hold([]); input.pressed.add('ControlLeft'); }, 'crouch');
simulate(60, () => hold([]), 'crouch idle');
simulate(40, () => hold(['KeyW']), 'crouch walk');
simulate(40, () => { hold([]); input.mouse.right = true; }, 'crouch aim idle + walk');
simulate(40, () => { hold(['KeyA']); input.mouse.right = true; }, 'crouch aim strafe');
simulate(40, () => { hold(['KeyS']); }, 'crouch walk back');
simulate(40, () => { hold(['KeyD']); }, 'crouch walk right');
simulate(40, () => { hold([]); input.pressed.add('ControlLeft'); }, 'stand up');
simulate(20, () => { hold([]); input.pressed.add('KeyZ'); }, 'go prone');
simulate(40, () => hold(['KeyW']), 'crawl forward');
simulate(40, () => hold(['KeyS']), 'crawl backward');
simulate(20, () => { hold([]); input.pressed.add('KeyZ'); }, 'get up');
simulate(150, () => hold([]), 'settle after getting up');
simulate(40, () => { hold([]); input.mouse.right = true; }, 'aim rifle');
simulate(40, () => { hold(['KeyW']); input.mouse.right = true; }, 'aim walk rifle');
simulate(40, () => { hold(['KeyW', 'ShiftLeft']); input.mouse.right = true; }, 'aim run rifle');
simulate(20, () => { hold([]); input.mouse.left = true; }, 'fire rifle');
simulate(30, () => { hold([]); input.pressed.add('KeyR'); }, 'reload rifle');
simulate(160, () => hold([]), 'wait for reload');
simulate(20, () => { hold([]); input.pressed.add('Digit1'); }, 'switch to pistol');
simulate(40, () => { hold([]); input.mouse.right = true; }, 'aim pistol');
simulate(30, () => { hold(['KeyA']); input.mouse.right = true; }, 'pistol aim strafe');
simulate(30, () => { hold(['KeyS']); input.mouse.right = true; }, 'pistol aim back');
simulate(20, () => { hold([]); input.mouse.left = true; }, 'fire pistol');
simulate(30, () => { hold([]); input.pressed.add('KeyJ'); }, 'heavy damage');
simulate(40, () => hold([]), 'settle');
simulate(30, () => { hold([]); input.pressed.add('KeyK'); }, 'die');
simulate(90, () => hold([]), 'death anim');
simulate(40, () => { hold([]); input.pressed.add('KeyG'); }, 'get up (revive)');

input.mouse.right = false;
input.mouse.left = false;

console.log(`\nclips exercised: ${[...seen].sort().join(', ')}`);
const unused = CLIP_DEFS.map((d) => d.id).filter((id) => !seen.has(id));
console.log(`clips never selected in this pass (expected for some one-shots): ${unused.join(', ') || 'none'}`);

if (missing.length) {
  console.log('\nPROBLEMS:');
  missing.forEach((m) => console.log(' -', m));
  process.exit(1);
}
console.log('\nState machine resolved a valid clip on every frame.');
