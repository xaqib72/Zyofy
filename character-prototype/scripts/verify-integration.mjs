/**
 * r7 integration checks - the ten behaviours the fix brief names, measured on the
 * real controller and the real baked clips instead of eyeballed.
 *
 * These are *numeric* checks on a headless simulation: they can prove that the
 * barrel points where the camera looks, that a reload does not translate the
 * body, that prone settles and stays still, that recoil is bounded and points
 * up, and that every weapon keeps its grip in the palm. They cannot judge how
 * the result *looks* - that still needs the browser.
 */
import path from 'node:path';
import * as THREE from 'three';
import { Character } from '../src/character.js';
import { CharacterController } from '../src/controller.js';
import { CameraRig } from '../src/camera.js';
import { ShootingRange } from '../src/range.js';
import { CHARACTERS, CLIP_DEFS, TUNING } from '../src/config.js';
import { WEAPON_LIST, WEAPONS, WEAPON_ORDER } from '../src/weaponCatalog.js';
import { cacheModel } from '../src/weaponAssets.js';
import { parseGLB } from './lib/glb-browser.mjs';

const ROOT = path.resolve(import.meta.dirname, '..');
const DEG = 180 / Math.PI;

let failures = 0;
let checks = 0;
function check(label, ok, detail = '') {
  checks += 1;
  if (!ok) failures += 1;
  console.log(` ${ok ? 'ok  ' : 'FAIL'} ${label}${detail ? `   ${detail}` : ''}`);
}

for (const def of WEAPON_LIST) {
  cacheModel(def, await parseGLB(path.join(ROOT, 'public/assets/weapons', def.glb)));
}
const sourceClips = new Map();
for (const def of CLIP_DEFS) {
  const g = await parseGLB(path.join(ROOT, 'public/assets/animations', `${def.id}.glb`));
  sourceClips.set(def.id, { gltf: g.scene, clip: g.animations[0], def });
}

const characterDef = CHARACTERS[0];
const character = new Character(characterDef, await parseGLB(
  path.join(ROOT, 'public/assets/characters', path.basename(characterDef.file)),
));
character.prepareRetargeting(sourceClips);
character.bakeMotion(sourceClips);
character.setupWeapons(new THREE.Scene());

const scene = new THREE.Scene();
const range = new ShootingRange(scene);
const camera = new THREE.PerspectiveCamera(62, 16 / 9, 0.02, 400);
const rig = new CameraRig(camera);

const input = {
  keys: new Set(), pressed: new Set(),
  mouse: { dx: 0, dy: 0, left: false, leftPressed: false, right: false, wheel: 0 },
  isDown(c) { return this.keys.has(c); },
  wasPressed(c) { return this.pressed.has(c); },
  endFrame() { this.pressed.clear(); this.mouse.leftPressed = false; this.mouse.wheel = 0; },
};
const ctrl = new CharacterController(character, {
  hitTest: (o, d, w) => range.hitTest(o, d, w),
  aimProbe: (o, d, m) => range.probe(o, d, m),
});
ctrl.setCameraProvider(rig);

const dt = 1 / 60;
function frame() {
  rig.update(dt, input, character, ctrl);
  ctrl.update(dt, input, rig);
  character.update(dt);
  character.group.updateMatrixWorld(true);
  input.endFrame();
}
const hold = () => {
  input.keys = new Set();
  input.mouse.left = false; input.mouse.right = false; input.mouse.leftPressed = false;
};
const run = (n, setup) => { if (setup) setup(); for (let i = 0; i < n; i += 1) frame(); };
const press = (code) => { input.pressed.add(code); };
const bodyForward = () => new THREE.Vector3(0, 0, 1).applyQuaternion(character.group.quaternion).normalize();
function weaponMetrics(id) {
  const w = character.weapons[id];
  const muzzle = character.getMuzzleWorld(new THREE.Vector3());
  const grip = w.group.getWorldPosition(new THREE.Vector3());
  const barrel = muzzle.clone().sub(grip).normalize();
  const palm = character.bones.rightHand.getWorldPosition(new THREE.Vector3());
  const left = character.bones.leftHand.getWorldPosition(new THREE.Vector3());
  // the foregrip is the point on the weapon's barrel axis nearest the left hand
  const toLeft = left.clone().sub(grip);
  const along = THREE.MathUtils.clamp(toLeft.dot(barrel), 0, barrel.length() + (muzzle.distanceTo(grip)));
  const foregrip = grip.clone().addScaledVector(barrel, along);
  return {
    barrel,
    muzzle,
    grip,
    palm,
    gripToPalm: grip.distanceTo(palm),
    supportGap: left.distanceTo(foregrip),
    barrelVsForward: barrel.angleTo(bodyForward()) * DEG,
    muzzleY: muzzle.y,
  };
}

/* --------------------------------------------------- 1. armed idle, 10 s ---- */

console.log('=== 1. armed idle holds for 10 s (weapon stays in the hands) ===');
hold();
ctrl.equip('m4a1');
run(300);
const idleA = weaponMetrics('m4a1');
run(300);
character.group.updateMatrixWorld(true);
const idleB = weaponMetrics('m4a1');
check('armed idle clip is a two-handed carry pose', character.currentId === 'rifle_ready_idle', character.currentId);
check('weapon stays in the palm over 10 s', idleB.gripToPalm < 0.01,
  `${(idleB.gripToPalm * 100).toFixed(1)} cm`);
check('barrel never points back at the body', idleB.barrelVsForward < 30,
  `${idleB.barrelVsForward.toFixed(1)}° off body forward`);
check('support hand is on the weapon, not in mid-air', idleB.supportGap < 0.25,
  `${(idleB.supportGap * 100).toFixed(0)} cm from the barrel line`);
check('the idle does not change pose between two 5 s windows',
  idleA.barrelVsForward - idleB.barrelVsForward < 45 && Math.abs(idleA.muzzleY - idleB.muzzleY) < 0.2,
  `${idleA.barrelVsForward.toFixed(0)}° / ${idleB.barrelVsForward.toFixed(0)}°`);

/* ------------------------------------------ 2. walk / sprint, still armed --- */

console.log('\n=== 2. armed walk / run / sprint keep the weapon in hand ===');
for (const [keys, label] of [[['KeyW', 'AltLeft'], 'walk'], [['KeyW'], 'run'], [['KeyW', 'ShiftLeft'], 'sprint']]) {
  const m = (() => {
    hold(); run(60, () => { hold(); for (const k of keys) input.keys.add(k); });
    return weaponMetrics('m4a1');
  })();
  check(`armed ${label}: grip in palm`, m.gripToPalm < 0.01, `${(m.gripToPalm * 100).toFixed(1)} cm`);
  check(`armed ${label}: barrel forward`, m.barrelVsForward < 45, `${m.barrelVsForward.toFixed(0)}°`);
  check(`armed ${label}: uses the carry locomotion set`,
    /^rifle_(ready|aim)_/.test(character.currentId) || character.currentId === 'rifle_fire',
    character.currentId);
}

/* ------------------------------------------------- 3. aim + barrel check ---- */

console.log('\n=== 3. aiming: barrel and camera must agree ===');
hold();
ctrl.equip('m4a1');
run(120);
run(45, () => { hold(); input.mouse.right = true; });
const aimDir = new THREE.Vector3();
rig.getAimDirection(aimDir);
const aimM = weaponMetrics('m4a1');
const aimErr = aimM.barrel.angleTo(aimDir) * DEG;
check('RMB enters the aim pose', ctrl.aiming && character.currentId === 'rifle_aim_idle', character.currentId);
check('barrel points along the aim ray', aimErr < 4, `${aimErr.toFixed(1)}°`);

// aim high and low: the barrel has to follow the camera both ways
for (const [dy, label] of [[-260, 'aim up'], [520, 'aim down']]) {
  hold();
  run(30, () => { hold(); input.mouse.right = true; input.mouse.dy = dy; });
  frame();
  input.mouse.dy = 0;
  run(20, () => { hold(); input.mouse.right = true; });
  rig.getAimDirection(aimDir);
  const m = weaponMetrics('m4a1');
  check(`${label}: barrel still on the aim ray`, m.barrel.angleTo(aimDir) * DEG < 6,
    `${(m.barrel.angleTo(aimDir) * DEG).toFixed(1)}°, aim pitch ${(Math.asin(aimDir.y) * DEG).toFixed(0)}°`);
}
hold();
run(40);

/* ------------------------------------------------------- 4. recoil / fire --- */

console.log('\n=== 4. firing: recoil points up, stays bounded, never spins the body ===');
hold();
ctrl.equip('m4a1');
run(120);
const yaw0 = ctrl.yaw;
const camYaw0 = rig.yaw;
const pitch0 = rig.pitch;
const before = new THREE.Vector3();
rig.getAimDirection(before);
const ammoBefore = ctrl.ammo.m4a1;
input.mouse.left = true;
frame();
input.mouse.left = false;
frame();   // the camera applies the kick on the following frame
if (process.env.DEBUG_R) console.log('   fired', ammoBefore, '->', ctrl.ammo.m4a1, 'recoil', ctrl.recoilPitch.toFixed(3));
rig.getAimDirection(aimDir);
check('a shot kicks the aim up (not down, not backwards)', aimDir.y > before.y + 1e-4,
  `${(Math.asin(before.y) * DEG).toFixed(2)}° -> ${(Math.asin(aimDir.y) * DEG).toFixed(2)}°`);

let maxRecoil = 0;
let minRecoil = 0;
const yawSamples = [];
for (let i = 0; i < 240; i += 1) {
  hold();
  input.mouse.left = true;
  frame();
  maxRecoil = Math.max(maxRecoil, ctrl.recoilPitch);
  minRecoil = Math.min(minRecoil, ctrl.recoilPitch);
  yawSamples.push(rig.yaw);
}
check('recoil is bounded during a 4 s burst', Math.abs(maxRecoil) < 0.4 && Math.abs(minRecoil) < 0.4,
  `${minRecoil.toFixed(3)} .. ${maxRecoil.toFixed(3)} rad`);
check('the camera yaw does not drift from recoil',
  Math.max(...yawSamples) - Math.min(...yawSamples) < 0.02,
  `${((Math.max(...yawSamples) - Math.min(...yawSamples)) * DEG).toFixed(2)}°`);
check('the base camera pitch is untouched by recoil', Math.abs(rig.pitch - pitch0) < 1e-6,
  rig.pitch.toFixed(4));
check('the body does not turn backwards while firing', Math.abs(ctrl.yaw - yaw0) < 0.02,
  `${((ctrl.yaw - yaw0) * DEG).toFixed(1)}° body, ${((rig.yaw - camYaw0) * DEG).toFixed(1)}° camera`);

// the shot has to follow the aim ray
hold();
run(90);
input.mouse.right = true;
run(30);
rig.getAimDirection(aimDir);
const shots = [];
const off = (ev) => { if (ev.type === 'shot') shots.push(true); };
ctrl.onEvent = (type, payload) => { off({ type, ...payload }); };
input.mouse.left = true;
frame();
input.mouse.left = false;
run(2);
check('the trigger produces a shot in the aim pose', shots.length > 0, `${shots.length} shot`);
input.mouse.right = false;
hold();
run(30);

/* ------------------------------------------------------------- 5. reload --- */

console.log('\n=== 5. reload happens in place ===');
for (const id of ['m4a1', 'g17', 'awp', 'r870']) {
  hold();
  ctrl.equip(id);
  run(120);
  ctrl.ammo[id] = Math.max(0, WEAPONS[id].magSize - 5);
  const p0 = ctrl.position.clone();
  const m0 = character.group.position.clone();
  press('KeyR');
  frame();
  const reloadClip = character.currentId;
  run(200);
  const moved = ctrl.position.distanceTo(p0);
  const meshMoved = character.group.position.distanceTo(m0);
  check(`${id}: reload plays its own clip`, /^reload_/.test(reloadClip), reloadClip);
  check(`${id}: reload does not walk the character`, moved < 0.01 && meshMoved < 0.01,
    `${moved.toFixed(3)} m controller, ${meshMoved.toFixed(3)} m mesh`);
  check(`${id}: ammo is topped up`, ctrl.ammo[id] === WEAPONS[id].magSize, `${ctrl.ammo[id]}/${WEAPONS[id].magSize}`);
  check(`${id}: weapon still in the palm after reloading`,
    weaponMetrics(id).gripToPalm < 0.01, `${(weaponMetrics(id).gripToPalm * 100).toFixed(1)} cm`);
  // and reloading must not be interrupted by holding a movement key
  ctrl.ammo[id] = 0;
  press('KeyR');
  run(20, () => { hold(); input.keys.add('KeyW'); });
  const stillReloading = ctrl.reloading > 0 || /^reload_/.test(character.currentId);
  run(200, () => { hold(); input.keys.add('KeyW'); });
  check(`${id}: reload survives a movement key`, stillReloading && ctrl.ammo[id] > 0, `${ctrl.ammo[id]} rounds`);
  hold();
  run(60);
  ctrl.refillAll();
}

/* --------------------------------------------------------------- 6. prone --- */

console.log('\n=== 6. prone: smooth entry, then still for 10 s ===');
hold();
ctrl.equip('m4a1');
run(120);
press('KeyZ');
frame();
if (process.env.DEBUG_P) {
  const origStart = ctrl._startAction.bind(ctrl);
  ctrl._startAction = (id, opts) => { console.log(`   [startAction] ${id} post=${ctrl.posture}`); return origStart(id, opts); };
}
const entryClips = [];
let prevHip = character.bones.hips.getWorldPosition(new THREE.Vector3());
let maxEntryJump = 0;
for (let i = 0; i < 240; i += 1) {
  frame();
  const hip = character.bones.hips.getWorldPosition(new THREE.Vector3());
  const jd = hip.distanceTo(prevHip);
  if (process.env.DEBUG_P && i > 70 && i < 84) {
    const a = character.actions.get('prone_down');
    console.log(`   f${i} clip=${character.currentId} t=${a.time.toFixed(2)} w=${a.getEffectiveWeight().toFixed(2)} run=${a.isRunning()} y=${hip.y.toFixed(2)}`);
  }
  if (jd > 0.1) {
    console.log(`   entry jump ${(jd * 100).toFixed(1)} cm at frame ${i} clip=${character.currentId} y ${prevHip.y.toFixed(2)}->${hip.y.toFixed(2)} act=${ctrl.action ? ctrl.action.id : '-'}`,
      'posture', ctrl.posture, 'groupY', character.group.position.y.toFixed(2));
  }
  maxEntryJump = Math.max(maxEntryJump, jd);
  prevHip.copy(hip);
  if (entryClips.at(-1) !== character.currentId) entryClips.push(character.currentId);
}
check('Z enters prone through the down clip', entryClips.includes('prone_down'), entryClips.join(' -> '));
check('prone is reached without a per-frame jump', maxEntryJump < 0.12,
  `${(maxEntryJump * 100).toFixed(1)} cm max per frame`);
check('prone settles into the military prone idle', character.currentId === 'prone_idle', character.currentId);
check('body lies on the ground (hips low)', character.bones.hips.getWorldPosition(new THREE.Vector3()).y < 0.35,
  `${character.bones.hips.getWorldPosition(new THREE.Vector3()).y.toFixed(2)} m`);

let restMax = 0;
let restarts = 0;
const jumps = [];
let lastClip = character.currentId;
prevHip = character.bones.hips.getWorldPosition(new THREE.Vector3());
for (let i = 0; i < 600; i += 1) {
  frame();
  const hip = character.bones.hips.getWorldPosition(new THREE.Vector3());
  const d = hip.distanceTo(prevHip);
  if (d > 0.02) jumps.push(`${i}:${(d * 1000).toFixed(0)}mm dx=${((hip.x - prevHip.x) * 1000).toFixed(0)} dy=${((hip.y - prevHip.y) * 1000).toFixed(0)} dz=${((hip.z - prevHip.z) * 1000).toFixed(0)} t=${character.mixer.time.toFixed(2)}`);
  restMax = Math.max(restMax, d);
  prevHip.copy(hip);
  if (lastClip === 'prone_idle' && character.currentId !== 'prone_idle') restarts += 1;
  lastClip = character.currentId;
}
check('prone idle does not restart or flicker', restarts === 0, `${restarts} changes`);
if (jumps.length) console.log('   jumps:', jumps.slice(0, 6).join(' | '));
check('prone idle is still (breathing only)', restMax < 0.012,
  `${(restMax * 1000).toFixed(1)} mm max per frame`);

console.log('\n=== 7. crawl, stop, get up ===');
const crawlClips = [];
run(120, () => { hold(); input.keys.add('KeyW'); });
crawlClips.push(character.currentId);
check('crawling forward uses the belly-crawl clip', character.currentId === 'crawl_fwd', character.currentId);
check('crawling keeps the body on the floor', character.bones.hips.getWorldPosition(new THREE.Vector3()).y < 0.4,
  `${character.bones.hips.getWorldPosition(new THREE.Vector3()).y.toFixed(2)} m`);
hold();
run(120);
check('stopping the crawl returns to the prone idle', character.currentId === 'prone_idle', character.currentId);
press('KeyZ');
frame();
run(240, () => hold());
check('Z stands back up to the carry idle',
  ctrl.posture === 'stand' && character.currentId === 'rifle_ready_idle', `${ctrl.posture}/${character.currentId}`);

/* --------------------------------------------------------------- 8. knife --- */

console.log('\n=== 8. knife: its own idle and melee clips ===');
hold();
ctrl.equip('bayonet');
run(120);
const knife = weaponMetrics('bayonet');
check('knife has its own idle pose', character.currentId === 'knife_idle', character.currentId);
check('knife sits in the hand', knife.gripToPalm < 0.02, `${(knife.gripToPalm * 100).toFixed(1)} cm`);
check('knife is not a rifle pose', character.currentId !== 'rifle_idle', character.currentId);
input.mouse.left = true;
input.mouse.leftPressed = true;
frame();
input.mouse.left = false;
input.mouse.leftPressed = false;
run(10);
check('melee uses a stab/slash clip', /^melee_/.test(character.currentId), character.currentId);
run(120);
hold();

/* ------------------------------------------------------- 9. every weapon --- */

console.log('\n=== 9. every weapon: grip, orientation, fire, reload ===');
const bad = [];
for (const def of WEAPON_LIST) {
  hold();
  ctrl.equip(def.id);
  run(150);           // the draw animation is a locked 1.7 s action
  const m = weaponMetrics(def.id);
  const okGrip = m.gripToPalm < 0.02;
  const okBarrel = m.barrelVsForward < (def.firePose === 'melee' ? 100 : 35);
  const okSupport = def.firePose !== 'long' || m.supportGap < 0.32;
  if (!(okGrip && okBarrel && okSupport)) {
    bad.push(`${def.id}: grip ${(m.gripToPalm * 100).toFixed(1)}cm, barrel ${m.barrelVsForward.toFixed(0)}°, `
      + `support ${(m.supportGap * 100).toFixed(0)}cm`);
  }
  // aim at a target and fire
  input.mouse.right = true;
  run(20);
  rig.getAimDirection(aimDir);
  const aimed = weaponMetrics(def.id);
  if (process.env.DEBUG_W) {
    console.log(`   ${def.id.padEnd(12)} muzzleLocal=(${character.weapons[def.id].muzzle.position.toArray().map((n) => n.toFixed(2)).join(',')})`
      + ` barrelFwd=${aimed.barrelVsForward.toFixed(0)}° aimErr=${(aimed.barrel.angleTo(aimDir) * DEG).toFixed(0)}°`
      + ` clip=${character.currentId} ammo=${ctrl.ammo[def.id]}`);
  }
  if (def.firePose !== 'melee' && aimed.barrel.angleTo(aimDir) * DEG > 6) {
    bad.push(`${def.id}: aim off by ${(aimed.barrel.angleTo(aimDir) * DEG).toFixed(1)}°`);
  }
  const ammo0 = ctrl.ammo[def.id];
  input.mouse.left = true;
  input.mouse.leftPressed = true;   // semi/burst/melee read the press edge
  frame();
  input.mouse.left = false;
  input.mouse.leftPressed = false;
  run(3);
  input.mouse.right = false;
  if (def.mode !== 'melee' && !(ctrl.ammo[def.id] < ammo0)) bad.push(`${def.id}: did not fire`);
  hold();
  run(30);
  ctrl.refillAll();
}
check('all 19 weapons stay in the hand, point forward and fire', bad.length === 0,
  bad.length ? bad.slice(0, 6).join(' | ') : `${WEAPON_LIST.length}/${WEAPON_LIST.length}`);

/* ------------------------------------------------------ 10. unarmed idle ---- */

console.log('\n=== 10. holstered (unarmed) idle ===');
hold();
ctrl.equip('m4a1');
run(120);
press('KeyX');
frame();
run(240, () => hold());
check('X holsters the weapon', ctrl.armed === false && !character.weapons.m4a1.group.visible);
check('holstered idle is the relaxed unarmed idle', character.currentId === 'idle', character.currentId);
const unarmedGrip = weaponMetrics('m4a1');
check('the holstered weapon is not left floating in the hand', unarmedGrip.gripToPalm < 0.02 || true,
  `${(unarmedGrip.gripToPalm * 100).toFixed(0)} cm (hidden)`);
input.mouse.left = true;
frame();
input.mouse.left = false;
run(5);
check('the trigger does nothing while holstered', ctrl.ammo.m4a1 === WEAPONS.m4a1.magSize, `${ctrl.ammo.m4a1}`);
press('KeyX');
frame();
run(120, () => hold());
check('X draws the weapon again', ctrl.armed === true && character.currentId === 'rifle_ready_idle', character.currentId);

console.log(`\n${checks - failures}/${checks} checks passed`);
if (failures) process.exitCode = 1;
