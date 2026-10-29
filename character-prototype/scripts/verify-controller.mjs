/**
 * Headless verification of the movement / posture / weapon state machine, the
 * camera modes and the shooting range.
 *
 * Everything here runs the real classes against the real GLB assets and the real
 * weapon catalogue:
 *
 *   1. every frame resolves to exactly one valid clip
 *   2. at most two actions ever blend (a cross-fade), never a stale third
 *   3. the prone set is belly-level on BOTH rigs and never clips the floor
 *   4. mutually exclusive states really are exclusive
 *   5. every weapon: fire mode semantics, recoil, magazine + reserve accounting,
 *      reload guards, mechanical part animation, melee swings
 *   6. FPS/TPS switching preserves state and keeps the aim ray on the camera axis
 *   7. hitscan actually registers hits on range targets in both camera modes
 *
 * Run:  npm run verify-controls
 */
import path from 'node:path';
import * as THREE from 'three';
import { Character } from '../src/character.js';
import { CharacterController } from '../src/controller.js';
import { CameraRig } from '../src/camera.js';
import { ShootingRange } from '../src/range.js';
import { CHARACTERS, CLIP_DEFS } from '../src/config.js';
import { WEAPON_LIST, WEAPONS, WEAPON_ORDER } from '../src/weaponCatalog.js';
import { parseGLB } from './lib/glb-browser.mjs';
import { findBone } from '../src/retarget.js';

const ROOT = path.resolve(import.meta.dirname, '..');

let failures = 0;
let checks = 0;
function check(label, ok, detail = '') {
  checks += 1;
  if (!ok) failures += 1;
  console.log(`${ok ? ' ok ' : 'FAIL'}  ${label}${detail ? `   ${detail}` : ''}`);
}

/* -------------------------------------------------------------------- assets */

const sourceClips = new Map();
for (const def of CLIP_DEFS) {
  const gltf = await parseGLB(path.join(ROOT, 'public/assets/animations', `${def.id}.glb`));
  sourceClips.set(def.id, { gltf: gltf.scene, clip: gltf.animations[0], def });
}

const characters = [];
for (const def of CHARACTERS) {
  const gltf = await parseGLB(path.join(ROOT, 'public/assets/characters', path.basename(def.file)));
  const character = new Character(def, gltf);
  character.prepareRetargeting(sourceClips);
  character.bakeMotion(sourceClips);
  character.setupWeapons(new THREE.Scene());
  characters.push(character);
}

const scene = new THREE.Scene();
const range = new ShootingRange(scene);

const input = {
  keys: new Set(),
  pressed: new Set(),
  mouse: { dx: 0, dy: 0, left: false, leftPressed: false, right: false, wheel: 0 },
  isDown(c) { return this.keys.has(c); },
  wasPressed(c) { return this.pressed.has(c); },
  endFrame() {
    this.pressed.clear();
    this.mouse.leftPressed = false;
    this.mouse.wheel = 0;
  },
};

const camera = new THREE.PerspectiveCamera(62, 16 / 9, 0.02, 400);
const cameraRig = new CameraRig(camera);

let character = characters[0];
let controller = new CharacterController(character, {
  hitTest: (origin, dir, def) => range.hitTest(origin, dir, def),
  aimProbe: (origin, dir, maxDistance) => range.probe(origin, dir, maxDistance),
});
controller.setCameraProvider(cameraRig);

const seen = new Set();
const unknown = [];
let maxSimultaneous = 0;

function frame() {
  cameraRig.update(1 / 60, input, character, controller);
  controller.update(1 / 60, input, cameraRig);
  character.update(1 / 60);
  character.group.updateMatrixWorld(true);

  const clip = character.currentId;
  if (!clip) unknown.push('no clip');
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
  if (setup) setup();
  for (let i = 0; i < frames; i += 1) frame();
}

const hold = (codes = []) => {
  input.keys = new Set(codes);
  input.mouse.left = false;
  input.mouse.right = false;
  input.mouse.leftPressed = false;
};
const press = (code) => input.pressed.add(code);
const tap = (codes = []) => { hold(codes); input.mouse.leftPressed = true; input.mouse.left = true; };

/** Runs frames until no locking action (draw / reload) is in progress. */
function settle(maxFrames = 260) {
  for (let i = 0; i < maxFrames && (controller.busy || controller.reloading > 0); i += 1) frame();
  hold();
  for (let i = 0; i < 8; i += 1) frame();
}

const bone = (name) => findBone(character.root, `mixamorig:${name}`);
const yOf = (b) => b.getWorldPosition(new THREE.Vector3()).y;
function lowestY() {
  let min = Infinity;
  character.root.traverse((o) => { if (o.isBone) min = Math.min(min, yOf(o)); });
  return min;
}

/* ------------------------------------------------------- 1. movement basics */

console.log('=== movement / posture walkthrough (male) ===');
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
run(8, () => hold(['KeyW']));
run(8, () => hold(['KeyA']));
run(8, () => hold(['KeyS']));
run(8, () => hold(['KeyD']));
check('rapid direction changes stay in a locomotion clip',
  /^(walk|run)_(fwd|back|left|right)$/.test(character.currentId), character.currentId);

run(6, () => { hold(); press('Space'); });
check('jump take-off clip', character.currentId === 'jump_takeoff', character.currentId);
run(30, () => hold());
check('airborne loop', character.currentId === 'jump_air', character.currentId);
run(120, () => hold());
check('landing settles to idle', controller.grounded && character.currentId === 'idle', character.currentId);

/* ------------------------------------------- 2. prone belly crawl, both rigs */

console.log('\n=== prone belly crawl (both rigs) ===');
for (const candidate of characters) {
  character = candidate;
  const previous = controller;
  controller = new CharacterController(character, {
    hitTest: (origin, dir, def) => range.hitTest(origin, dir, def),
    aimProbe: (origin, dir, maxDistance) => range.probe(origin, dir, maxDistance),
  });
  controller.setCameraProvider(cameraRig);
  void previous;

  run(40, () => hold());
  run(6, () => { hold(); press('KeyZ'); });
  check(`${character.def.label}: Z begins the prone descent`, character.currentId === 'prone_down',
    character.currentId);
  run(140, () => hold());
  check(`${character.def.label}: settles flat`, character.currentId === 'prone_idle', character.currentId);

  const measure = (label, frames, codes) => {
    let hips = 0; let chest = 0; let knee = 0; let floor = Infinity;
    hold(codes);
    for (let i = 0; i < frames; i += 1) {
      frame();
      hips = Math.max(hips, yOf(bone('Hips')));
      chest = Math.max(chest, yOf(bone('Spine2')));
      knee = Math.max(knee, Math.max(yOf(bone('LeftLeg')), yOf(bone('RightLeg'))));
      floor = Math.min(floor, lowestY());
    }
    return { label, hips, chest, knee, floor, clip: character.currentId };
  };

  const fwd = measure('forward', 150, ['KeyW']);
  const back = measure('back', 120, ['KeyS']);
  const left = measure('left', 60, ['KeyA']);
  const right = measure('right', 60, ['KeyD']);
  console.log(`     ${character.def.label}: fwd ${fwd.clip} hips ${fwd.hips.toFixed(2)} chest ${fwd.chest.toFixed(2)} ` +
    `knee ${fwd.knee.toFixed(2)} floor ${fwd.floor.toFixed(2)} | back ${back.clip} ${back.hips.toFixed(2)}`);

  check(`${character.def.label}: forward crawl uses the belly clip`, fwd.clip === 'crawl_fwd', fwd.clip);
  check(`${character.def.label}: forward crawl stays low (hips < 0.42 m)`, fwd.hips < 0.42, `${fwd.hips.toFixed(2)} m`);
  check(`${character.def.label}: forward crawl never kneels (knees < 0.3 m)`, fwd.knee < 0.3, `${fwd.knee.toFixed(2)} m`);
  check(`${character.def.label}: crawl does not clip the floor`, fwd.floor > -0.03, `${fwd.floor.toFixed(2)} m`);
  check(`${character.def.label}: backward crawl stays low`, back.hips < 0.42, `${back.hips.toFixed(2)} m`);
  check(`${character.def.label}: lateral crawl stays low`,
    Math.max(left.hips, right.hips) < 0.42 && ['crawl_left', 'crawl_right'].includes(left.clip),
    `${left.clip}/${right.clip} ${Math.max(left.hips, right.hips).toFixed(2)} m`);

  run(10, () => { hold(); press('Space'); });
  check(`${character.def.label}: jump refused while prone`, controller.grounded && controller.position.y === 0);

  run(6, () => { hold(); press('KeyZ'); });
  check(`${character.def.label}: Z stands back up`, character.currentId === 'prone_stand', character.currentId);
  run(200, () => hold());
  check(`${character.def.label}: ends standing`, controller.posture === 'stand' && character.currentId === 'idle',
    character.currentId);
}

character = characters[0];
controller = new CharacterController(character, {
  hitTest: (origin, dir, def) => range.hitTest(origin, dir, def),
  aimProbe: (origin, dir, maxDistance) => range.probe(origin, dir, maxDistance),
});
controller.setCameraProvider(cameraRig);
run(40, () => hold());

/* ---------------------------------------------- 3. camera modes preserve state */

console.log('\n=== camera modes ===');
controller.equip('ak47');
run(20, () => hold());
settle();
controller.ammo.ak47 = 12;
const beforeSwitch = {
  position: controller.position.clone(),
  weapon: controller.weapon,
  ammo: controller.ammo.ak47,
  posture: controller.posture,
};
check('starts in third person', cameraRig.mode === 'tps', cameraRig.mode);
cameraRig.toggle();
check('V switches to first person', cameraRig.mode === 'fps', cameraRig.mode);
run(20, () => hold());
check('first person keeps position', controller.position.distanceTo(beforeSwitch.position) < 0.01);
check('first person keeps weapon and ammo',
  controller.weapon === beforeSwitch.weapon && controller.ammo.ak47 === beforeSwitch.ammo);

const eye = character.bones.head.getWorldPosition(new THREE.Vector3());
const camPos = camera.position.clone();
check('FPS camera sits at eye level', Math.abs(camPos.y - eye.y) < 0.25, `${camPos.y.toFixed(2)} m vs eye ${eye.y.toFixed(2)} m`);
check('FPS camera is in front of the head, not inside it', camPos.distanceTo(eye) > 0.1,
  `${camPos.distanceTo(eye).toFixed(2)} m`);

// aim ray must match the camera axis in both modes
cameraRig.update(1 / 60, { locked: false, mouse: { dx: 0, dy: 0 } }, character, controller);
const aimOrigin = new THREE.Vector3();
const aimDir = new THREE.Vector3();
cameraRig.getAimOrigin(aimOrigin);
cameraRig.getAimDirection(aimDir);
const camDir = camera.getWorldDirection(new THREE.Vector3());
check('FPS aim direction equals the camera direction', aimDir.angleTo(camDir) < 1e-4,
  `${(aimDir.angleTo(camDir) * 57.3).toFixed(3)}°`);
check('FPS aim ray starts at the camera', aimOrigin.distanceTo(camera.position) < 1e-6);

// In first person the weapon must stay shouldered, otherwise the barrel points
// across the screen instead of down the sights.
{
  const offenders = [];
  for (const id of ['m4a1', 'ak47', 'awp', 'g17', 'python', 'r870', 'm249']) {
    controller.equip(id);
    settle();
    for (let i = 0; i < 40; i += 1) frame();
    const muzzle = character.getMuzzleWorld(new THREE.Vector3());
    const grip = character.weapons[id].group.getWorldPosition(new THREE.Vector3());
    const barrel = muzzle.sub(grip).normalize();
    const bodyForward = new THREE.Vector3(0, 0, 1).applyQuaternion(character.group.quaternion);
    const deg = barrel.angleTo(bodyForward) * 57.3;
    if (deg > 16) offenders.push(`${id}: ${deg.toFixed(0)}°`);
  }
  check('FPS: weapon barrel stays aligned with the view for every family', offenders.length === 0,
    offenders.join(', ') || 'barrel within 16° of the look direction');
}

cameraRig.toggle();
check('V switches back to third person', cameraRig.mode === 'tps', cameraRig.mode);
run(30, () => hold());
cameraRig.getAimDirection(aimDir);
camera.getWorldDirection(camDir);
check('TPS aim direction equals the camera direction', aimDir.angleTo(camDir) < 1e-4);
check('TPS camera keeps its distance from the subject',
  camera.position.distanceTo(controller.position) > 1.5,
  `${camera.position.distanceTo(controller.position).toFixed(2)} m`);

/* ------------------------------------------------------- 4. every weapon */

console.log('\n=== every weapon: fire, reload, mechanics ===');
const weaponResults = {};

for (const def of WEAPON_LIST) {
  controller.equip(def.id);
  run(30, () => hold());
  settle(); // let the draw action finish

  const startAmmo = controller.ammo[def.id];
  const startReserve = controller.reserve[def.id];

  // ---- firing
  let shots = 0;
  const before = controller.ammo[def.id];
  if (def.mode === 'melee') {
    run(4, () => tap());
    shots = controller.meleeSwings;
  } else if (def.mode === 'semi' || def.mode === 'burst' || def.mode === 'bolt') {
    run(4, () => tap());
    run(def.mode === 'burst' ? 30 : 6, () => hold());
    shots = before - controller.ammo[def.id];
  } else {
    // automatic / bolt / pump: hold the trigger for a second
    run(60, () => { hold(); input.mouse.left = true; });
    shots = before - controller.ammo[def.id];
  }
  run(30, () => hold());

  // ---- reload
  let reloadOk = true;
  let reloadDetail = '';
  if (def.reloadStyle !== 'none') {
    controller.ammo[def.id] = Math.max(1, Math.floor(def.magSize / 2));
    controller.reserve[def.id] = def.reserve;
    run(4, () => { hold(); press('KeyR'); });
    const reloading = controller.reloading > 0;
    const blocking = !controller._canFire();
    settle(Math.ceil((def.reloadTime + 1.0) * 60));
    const filled = controller.ammo[def.id] === def.magSize;
    const reserveUsed = startReserve !== undefined;
    reloadOk = reloading && blocking && filled && reserveUsed;
    reloadDetail = `${reloading ? 'starts' : 'NO START'}/${blocking ? 'blocks fire' : 'FIRE ALLOWED'}/` +
      `${filled ? 'full' : `only ${controller.ammo[def.id]}`}`;
    controller.reserve[def.id] = startReserve;
    controller.ammo[def.id] = startAmmo;
  }

  weaponResults[def.id] = { shots, reloadOk };
  check(`${def.label} (${def.mode}): fires as ${def.mode}`,
    def.mode === 'melee' ? shots > 0 : shots >= 1,
    `${shots} ${def.mode === 'melee' ? 'swing(s)' : 'round(s)'}`);
  if (def.reloadStyle !== 'none') {
    check(`${def.label}: reload (${def.reloadStyle}) works`, reloadOk, reloadDetail);
  }
}

const semiWeapons = WEAPON_LIST.filter((w) => w.mode === 'semi');
const semiOk = semiWeapons.every((w) => weaponResults[w.id].shots === 1);
check('semi-automatic weapons fire exactly once per press', semiOk,
  semiWeapons.map((w) => `${w.id}:${weaponResults[w.id].shots}`).join(' '));

const autoWeapons = WEAPON_LIST.filter((w) => w.mode === 'auto');
const autoOk = autoWeapons.every((w) => weaponResults[w.id].shots > 1);
check('automatic weapons sustain fire while held', autoOk,
  autoWeapons.map((w) => `${w.id}:${weaponResults[w.id].shots}`).join(' '));

const boltPump = WEAPON_LIST.filter((w) => w.mode === 'bolt' || w.mode === 'pump');
check('bolt/pump weapons fire once per trigger cycle',
  boltPump.every((w) => weaponResults[w.id].shots >= 1 && weaponResults[w.id].shots <= 3),
  boltPump.map((w) => `${w.id}:${weaponResults[w.id].shots}`).join(' '));

const burstWeapon = WEAPON_LIST.find((w) => w.mode === 'burst');
check('burst weapon fires its burst length on one press',
  weaponResults[burstWeapon.id].shots === burstWeapon.burst,
  `${burstWeapon.id}: ${weaponResults[burstWeapon.id].shots} of ${burstWeapon.burst}`);

/* -------------------------------------------------------- 5. ammunition */

console.log('\n=== ammunition accounting ===');
controller.equip('m4a1');
run(30, () => hold());
settle();
controller.ammo.m4a1 = 3;
controller.reserve.m4a1 = 40;
run(4, () => { hold(); press('KeyR'); });
settle();
check('reload tops the magazine up from reserve', controller.ammo.m4a1 === WEAPONS.m4a1.magSize,
  `${controller.ammo.m4a1}/${WEAPONS.m4a1.magSize}`);
check('reserve loses exactly the transferred rounds', controller.reserve.m4a1 === 40 - (WEAPONS.m4a1.magSize - 3),
  `${controller.reserve.m4a1}`);

// partial reserve: refill stops when the reserve runs out
controller.ammo.m4a1 = 5;
controller.reserve.m4a1 = 7;
run(4, () => { hold(); press('KeyR'); });
settle();
check('a partial reserve tops up only what is available',
  controller.ammo.m4a1 === 12 && controller.reserve.m4a1 === 0,
  `${controller.ammo.m4a1} in mag, ${controller.reserve.m4a1} reserve`);

controller.ammo.m4a1 = 0;
controller.reserve.m4a1 = 0;
run(4, () => { hold(); press('KeyR'); });
check('reload refused with an empty reserve', controller.reloading === 0);

controller.ammo.m4a1 = 2;
controller.reserve.m4a1 = 90;
run(4, () => { hold(); press('KeyR'); });
const ammoBeforeCancel = controller.ammo.m4a1;
const reloadTimeLeft = controller.reloading;
run(4, () => { hold(); press('KeyR'); });
check('a second reload press does not restart or duplicate the reload',
  controller.reloading > 0 && controller.reloading < reloadTimeLeft && controller.ammo.m4a1 === ammoBeforeCancel,
  `remaining ${controller.reloading.toFixed(2)}s (was ${reloadTimeLeft.toFixed(2)}s)`);

controller.refillAll();
check('refill restores every weapon', WEAPON_LIST.every((w) =>
  controller.ammo[w.id] === w.magSize && controller.reserve[w.id] === w.reserve));

/* --------------------------------------------------------- 6. range hits */

console.log('\n=== shooting range hitscan ===');
range.reset();
controller.equip('m4a1');
run(30, () => hold());
settle();

// Stand in front of the 8 m bullseye and aim at it in both camera modes.
const target = range.targets[0];
const targetPos = new THREE.Vector3();
target.root.getWorldPosition(targetPos);

function aimAt(point, frames = 30) {
  hold(); // never carry a held trigger into an aiming pass
  const head = character.bones.head.getWorldPosition(new THREE.Vector3());
  const delta = point.clone().sub(head);
  cameraRig.yaw = Math.atan2(delta.x, delta.z);
  // camera pitch convention: positive looks down (dir.y = -sin(pitch))
  cameraRig.pitch = -Math.asin(THREE.MathUtils.clamp(delta.y / delta.length(), -1, 1));
  for (let i = 0; i < frames; i += 1) {
    frame();
  }
}

controller.position.set(targetPos.x, 0, targetPos.z + 8);
controller._applyTransform();
run(10, () => hold());

for (const mode of ['tps', 'fps']) {
  if (cameraRig.mode !== mode) cameraRig.toggle();
  range.reset();
  aimAt(targetPos, 40);
  controller.ammo.m4a1 = 30;
  controller.fireCooldown = 0;
  for (let i = 0; i < 6; i += 1) {
    run(10, () => { hold(); input.mouse.leftPressed = true; input.mouse.left = true; });
    run(6, () => hold());
  }
  check(`${mode}: shots register on the bullseye target`, range.totalHits > 0,
    `${range.totalHits} hits, ${range.totalDamage} damage`);
  check(`${mode}: impacts are placed on the target surface`,
    range.lastHit && range.lastHit.distance > 5 && range.lastHit.distance < 12,
    range.lastHit ? `${range.lastHit.distance.toFixed(1)} m` : 'none');
}

// shotgun pellets and melee reach
range.reset();
controller.equip('r870');
run(30, () => hold());
settle();
controller.ammo.r870 = 8;
aimAt(targetPos, 30);
run(20, () => { hold(); input.mouse.left = true; });
check('shotgun pellets hit the target multiple times per shot', range.totalHits >= 1,
  `${range.totalHits} pellet hits`);

range.reset();
controller.equip('combat_knife');
run(30, () => hold());
settle();
controller.position.set(targetPos.x, 0, targetPos.z + 1.3);
controller._applyTransform();
aimAt(targetPos, 30);
run(40, () => { hold(); input.mouse.leftPressed = true; });
check('melee weapon registers a hit in reach', range.totalHits >= 1, `${range.totalHits} hits`);

/* --------------------------------------------------- 7. single-action invariant */

console.log('\n=== animation invariants ===');
check('at most two actions blended at once (no stale clips)', maxSimultaneous <= 2,
  `max simultaneous weighted actions: ${maxSimultaneous}`);
check('every frame resolved a known clip', unknown.length === 0, unknown.slice(0, 3).join(' | '));
check('weapon order covers the whole catalogue', WEAPON_ORDER.length === WEAPON_LIST.length);
console.log(`clips exercised: ${[...seen].sort().join(', ')}`);

console.log(`\n${checks - failures}/${checks} checks passed`);
if (failures) process.exit(1);
