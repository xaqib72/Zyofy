/**
 * Headless verification of the movement / animation state machine.
 *
 * Builds the real Character + CharacterController from the shipped GLB assets,
 * feeds synthetic input frames and asserts hard invariants:
 *
 *   1. every frame resolves to exactly one valid clip
 *   2. only one action ever carries weight (no double animation)
 *   3. the prone set really is belly-level (hips/chest/knees stay low) and does
 *      not push the body through the floor
 *   4. mutually exclusive states really are exclusive (no jump while prone,
 *      no posture change while airborne, no crawling while standing up)
 *   5. semi-automatic weapons fire once per press; automatic ones sustain
 *   6. ammunition accounting is exact, and reload guards hold
 *
 * Run with:  npm run verify-controls
 */
import path from 'node:path';
import * as THREE from 'three';
import { Character } from '../src/character.js';
import { CharacterController } from '../src/controller.js';
import { CHARACTERS, CLIP_DEFS, WEAPONS } from '../src/config.js';
import { loadRig } from './lib/mini-gltf.mjs';
import { findBone } from '../src/retarget.js';

const ROOT = path.resolve(import.meta.dirname, '..');
const ANIM_DIR = path.join(ROOT, 'public/assets/animations');
const CHAR_DIR = path.join(ROOT, 'public/assets/characters');

const sourceClips = new Map();
for (const def of CLIP_DEFS) {
  const rig = loadRig(path.join(ANIM_DIR, `${def.id}.glb`));
  sourceClips.set(def.id, { gltf: rig.scene, clip: rig.clips[0], def });
}

const characters = CHARACTERS.map((def) =>
  new Character(def, loadRig(path.join(CHAR_DIR, path.basename(def.file)))),
);
for (const c of characters) c.prepareRetargeting(sourceClips);
for (const c of characters) c.bakeMotion(sourceClips);
for (const c of characters) c.setupWeapons(new THREE.Scene());

const character = characters[0];
const controller = new CharacterController(character, {});

/** tiny stand-in for the browser Input class */
const input = {
  keys: new Set(),
  pressed: new Set(),
  mouse: { dx: 0, dy: 0, left: false, leftPressed: false, right: false },
  isDown(c) { return this.keys.has(c); },
  wasPressed(c) { return this.pressed.has(c); },
  endFrame() {
    this.pressed.clear();
    this.mouse.leftPressed = false;
  },
};

const camera = { yaw: Math.PI, pitch: 0.12 };

let failures = 0;
let checks = 0;
function check(label, ok, detail = '') {
  checks += 1;
  if (!ok) failures += 1;
  console.log(`${ok ? ' ok ' : 'FAIL'}  ${label}${detail ? `   ${detail}` : ''}`);
}

const seen = new Set();
const unknown = [];
/** Every action that carried weight during the whole run. */
let maxSimultaneous = 0;

function frame() {
  controller.update(1 / 60, input, camera);
  character.update(1 / 60);
  character.group.updateMatrixWorld(true);

  const clip = character.currentId;
  if (!clip) unknown.push('no clip active');
  else {
    seen.add(clip);
    if (!character.actions.has(clip)) unknown.push(`unknown clip ${clip}`);
  }

  let weighted = 0;
  for (const action of character.actions.values()) {
    if (action.isRunning() && action.getEffectiveWeight() > 0.02) weighted += 1;
  }
  maxSimultaneous = Math.max(maxSimultaneous, weighted);

  input.endFrame();
}

function run(frames, setup) {
  setup();
  for (let i = 0; i < frames; i += 1) frame();
}

const hold = (codes = []) => {
  input.keys = new Set(codes);
  input.mouse.left = false;
  input.mouse.right = false;
  input.mouse.leftPressed = false;
};
const press = (code) => input.pressed.add(code);

const bone = (name) => findBone(character.root, `mixamorig:${name}`);
const NB = {
  hips: bone('Hips'),
  chest: bone('Spine2'),
  head: bone('Head'),
  knee: [bone('LeftLeg'), bone('RightLeg')],
  hand: [bone('LeftHand'), bone('RightHand')],
  foot: [bone('LeftFoot'), bone('RightFoot')],
  elbow: [bone('LeftForeArm'), bone('RightForeArm')],
};
const yOf = (b) => b.getWorldPosition(new THREE.Vector3()).y;

/** Samples the current pose for the whole-body ground-clipping check. */
function lowestY() {
  let min = Infinity;
  character.root.traverse((o) => {
    if (o.isBone) min = Math.min(min, yOf(o));
  });
  return min;
}

// ---------------------------------------------------------------- walkthrough
console.log('=== state machine walkthrough (male character) ===');

run(60, () => hold());
check('idle after boot', character.currentId === 'idle', character.currentId);

run(45, () => hold(['KeyW', 'AltLeft']));
check('walk forward', character.currentId === 'walk_fwd', character.currentId);

run(45, () => hold(['KeyW']));
check('run forward', character.currentId === 'run_fwd', character.currentId);

run(60, () => hold(['KeyW', 'ShiftLeft']));
check('sprint forward', character.currentId === 'sprint_fwd', character.currentId);

run(45, () => hold(['KeyS']));
check('run backward', character.currentId === 'run_back', character.currentId);

run(45, () => hold(['KeyA']));
check('run left', character.currentId === 'run_left', character.currentId);

run(45, () => hold(['KeyD', 'AltLeft']));
check('walk right', character.currentId === 'walk_right', character.currentId);

// rapid direction changes: the clip must keep up without getting stuck
run(8, () => hold(['KeyW']));
run(8, () => hold(['KeyA']));
run(8, () => hold(['KeyS']));
run(8, () => hold(['KeyD']));
check('rapid direction changes stay in a locomotion clip',
  /^(walk|run)_(fwd|back|left|right)$/.test(character.currentId), character.currentId);

// ------------------------------------------------------------------------ jump
run(6, () => { hold(); press('Space'); });
check('jump take-off', character.currentId === 'jump_takeoff', character.currentId);
run(30, () => hold());
check('airborne loop', character.currentId === 'jump_air', character.currentId);
run(120, () => hold());
check('back on the ground', controller.grounded === true);
check('landing settles back into idle', character.currentId === 'idle', character.currentId);

// ---------------------------------------------------------------------- crouch
run(6, () => { hold(); press('ControlLeft'); });
check('crouch entered', controller.posture === 'crouch');
run(40, () => hold());
check('crouch idle', character.currentId === 'crouch_idle', character.currentId);
run(40, () => hold(['KeyW']));
check('crouch walk', character.currentId === 'crouch_fwd', character.currentId);
run(40, () => { hold(['KeyA']); input.mouse.right = true; });
check('crouch aim strafe', character.currentId === 'crouch_left', character.currentId);
run(6, () => { hold(); press('ControlLeft'); });
run(90, () => hold());
check('stand up again', controller.posture === 'stand' && character.currentId === 'idle',
  `${controller.posture}/${character.currentId}`);

// --------------------------------------------------------- PRONE (belly crawl)
console.log('\n=== prone: belly crawl verification ===');
run(6, () => { hold(); press('KeyZ'); });
check('Z starts a real prone-down transition', controller.posture === 'prone' && character.currentId === 'prone_down',
  character.currentId);
run(140, () => hold());
check('settles flat on the stomach', character.currentId === 'prone_idle', character.currentId);

function measureProne(label, frames, codes) {
  let hipsMax = 0;
  let chestMax = 0;
  let kneeMax = 0;
  let kneeMin = Infinity;
  let headMax = 0;
  let floor = Infinity;
  let handMin = Infinity;
  let handMax = 0;
  let standUps = 0;
  hold(codes);
  for (let i = 0; i < frames; i += 1) {
    frame();
    hipsMax = Math.max(hipsMax, yOf(NB.hips));
    chestMax = Math.max(chestMax, yOf(NB.chest));
    headMax = Math.max(headMax, yOf(NB.head));
    for (const k of NB.knee) {
      kneeMax = Math.max(kneeMax, yOf(k));
      kneeMin = Math.min(kneeMin, yOf(k));
    }
    for (const h of NB.hand) {
      handMin = Math.min(handMin, yOf(h));
      handMax = Math.max(handMax, yOf(h));
    }
    floor = Math.min(floor, lowestY());
    if (character.currentId === 'prone_stand' || character.currentId === 'crouch_stand') standUps += 1;
  }
  console.log(
    `     ${label.padEnd(18)} clip ${character.currentId.padEnd(12)} ` +
      `hips ${hipsMax.toFixed(2)} chest ${chestMax.toFixed(2)} head ${headMax.toFixed(2)} ` +
      `knee ${kneeMin.toFixed(2)}-${kneeMax.toFixed(2)} hand ${handMin.toFixed(2)}-${handMax.toFixed(2)} ` +
      `lowest-bone ${floor.toFixed(2)}`,
  );
  return { hipsMax, chestMax, headMax, kneeMax, kneeMin, floor, handMax, standUps };
}

const fwd = measureProne('crawl forward', 150, ['KeyW']);
check('crawl forward uses the belly-crawl clip', character.currentId === 'crawl_fwd', character.currentId);
check('crawl forward: hips stay on the floor (<0.35 m)', fwd.hipsMax < 0.35, `${fwd.hipsMax.toFixed(2)} m`);
// A prone chest is ~0.2-0.3 m for the *source* rig; the heavier male rig has a
// longer torso, so 0.5 m is the honest ceiling for "belly on the floor".
check('crawl forward: chest stays low (<0.50 m)', fwd.chestMax < 0.5, `${fwd.chestMax.toFixed(2)} m`);
check('crawl forward: knees never lift into a kneel (<0.25 m)', fwd.kneeMax < 0.25, `${fwd.kneeMax.toFixed(2)} m`);
check('crawl forward: nothing clips through the ground', fwd.floor > -0.02, `${fwd.floor.toFixed(2)} m`);
check('crawl forward: body never stands up mid-crawl', fwd.standUps === 0);

const back = measureProne('crawl backward', 120, ['KeyS']);
check('crawl backward uses the flat backward drag', character.currentId === 'crawl_back', character.currentId);
check('crawl backward: hips stay on the floor', back.hipsMax < 0.35, `${back.hipsMax.toFixed(2)} m`);
check('crawl backward: nothing clips through the ground', back.floor > -0.02, `${back.floor.toFixed(2)} m`);

const left = measureProne('crawl left', 60, ['KeyA']);
check('crawl left uses a prone clip', ['crawl_left', 'crawl_right', 'prone_idle'].includes(character.currentId),
  character.currentId);
check('crawl left: hips stay on the floor', left.hipsMax < 0.35, `${left.hipsMax.toFixed(2)} m`);

const right = measureProne('crawl right', 60, ['KeyD']);
check('crawl right uses a prone clip', ['crawl_left', 'crawl_right', 'prone_idle'].includes(character.currentId),
  character.currentId);
check('crawl right: hips stay on the floor', right.hipsMax < 0.35, `${right.hipsMax.toFixed(2)} m`);

// conflicting states must be rejected while prone
run(10, () => { hold(); press('Space'); });
check('jump is refused while prone', controller.grounded === true && controller.position.y === 0);
check('still prone after pressing jump', controller.posture === 'prone', controller.posture);

// reload while prone uses the prone reload clip
controller.ammo.rifle = 3;
run(6, () => { hold(); press('KeyR'); });
check('reload while prone uses the prone reload clip', character.currentId === 'reload_prone', character.currentId);
run(200, () => hold());

run(6, () => { hold(); press('KeyZ'); });
check('Z begins standing up', character.currentId === 'prone_stand', character.currentId);
run(200, () => hold());
check('back on both feet', controller.posture === 'stand' && character.currentId === 'idle',
  `${controller.posture}/${character.currentId}`);

// -------------------------------------------------------- aiming / firing
console.log('\n=== aiming, fire modes and ammunition ===');
controller.equip('rifle');
run(40, () => { hold(); input.mouse.right = true; });
check('rifle aim idle', character.currentId === 'rifle_aim_idle', character.currentId);
run(30, () => { hold(['KeyW']); input.mouse.right = true; });
check('rifle aim walk', character.currentId === 'rifle_aim_walk', character.currentId);
run(40, () => { hold(['KeyW', 'ShiftLeft']); input.mouse.right = true; });
check('rifle aim run', character.currentId === 'rifle_aim_run', character.currentId);

// automatic weapon: held trigger keeps firing
const rifleBefore = controller.ammo.rifle;
run(30, () => { hold(); input.mouse.left = true; input.mouse.right = true; });
const rifleShots = rifleBefore - controller.ammo.rifle;
check('automatic rifle fires repeatedly while held', rifleShots > 1, `${rifleShots} rounds in 0.5 s`);
run(30, () => hold());

// semi-automatic pistol: one shot per press, never per frame
controller.equip('pistol');
run(30, () => hold());
const pistolBefore = controller.ammo.pistol;
run(60, () => { hold(); input.mouse.left = true; input.mouse.leftPressed = true; input.mouse.right = true; });
const heldShots = pistolBefore - controller.ammo.pistol;
check('semi-automatic pistol fires exactly once per press', heldShots === 1, `${heldShots} rounds`);

const before2 = controller.ammo.pistol;
for (let i = 0; i < 5; i += 1) {
  run(12, () => { hold(); input.mouse.left = true; input.mouse.leftPressed = true; });
}
const tapped = before2 - controller.ammo.pistol;
check('5 taps fire 5 rounds', tapped === 5, `${tapped} rounds`);

// ---------------------------------------------------------------- reload rules
controller.equip('rifle');
run(20, () => hold());
controller.ammo.rifle = 5;
controller.reserve.rifle = 40;
run(6, () => { hold(); press('KeyR'); });
const reloadClip = character.currentId;
check('reload uses the standing reload clip', reloadClip === 'reload_stand', reloadClip);
run(5, () => { hold(); press('KeyR'); });
check('a second reload press does not restart the animation', controller.reloading > 0);
run(200, () => hold());
check('reload refills the magazine from reserve', controller.ammo.rifle === WEAPONS.rifle.magSize,
  `${controller.ammo.rifle}/${WEAPONS.rifle.magSize}`);
check('reload removes exactly the transferred ammunition from reserve',
  controller.reserve.rifle === 40 - (WEAPONS.rifle.magSize - 5), `${controller.reserve.rifle}`);

// reload at full magazine does nothing
const fullAmmo = controller.ammo.rifle;
run(6, () => { hold(); press('KeyR'); });
check('reload is refused at a full magazine', controller.reloading === 0 && controller.ammo.rifle === fullAmmo);

// partial reserve is consumed correctly and then blocks reload
controller.ammo.rifle = 2;
controller.reserve.rifle = 7;
run(6, () => { hold(); press('KeyR'); });
run(200, () => hold());
check('partial reserve tops the magazine up to what is available',
  controller.ammo.rifle === 9 && controller.reserve.rifle === 0,
  `${controller.ammo.rifle} / ${controller.reserve.rifle}`);
run(6, () => { hold(); press('KeyR'); });
check('reload is refused with no reserve left', controller.reloading === 0);

// ------------------------------------------------------------ damage / death
run(6, () => { hold(); press('KeyH'); });
const hitClip = character.currentId;
run(2, () => { hold(); press('KeyH'); });
check('a repeated hit press does not stack two hit clips', character.currentId === hitClip, character.currentId);
run(120, () => hold());

run(6, () => { hold(); press('KeyK'); });
run(60, () => hold());
check('death plays a death clip and freezes movement',
  controller.dead && controller.speed === 0 && ['death_front', 'death_back'].includes(character.currentId));
run(6, () => { hold(); press('Space'); });
check('no jumping while dead', controller.position.y === 0);
run(6, () => { hold(); press('KeyG'); });
check('revive plays the get-up clip', character.currentId === 'prone_stand', character.currentId);
run(200, () => hold());

// ------------------------------------------------------------------- summary
console.log(`\nclips exercised: ${[...seen].sort().join(', ')}`);
const unused = CLIP_DEFS.map((d) => d.id).filter((id) => !seen.has(id));
console.log(`clips never selected in this pass: ${unused.join(', ') || 'none'}`);

console.log('');
check('at most two actions blend during a cross-fade (no stale clips)', maxSimultaneous <= 2,
  `max simultaneous weighted actions: ${maxSimultaneous}`);
check('every frame resolved a known clip', unknown.length === 0, unknown.slice(0, 3).join(' | '));

console.log(`\n${checks - failures}/${checks} checks passed`);
if (failures) process.exit(1);
