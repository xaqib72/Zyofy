import * as THREE from 'three';
import { Hud } from './hud.js';
import { Input } from './input.js';
import { ThirdPersonCamera } from './camera.js';
import { Character } from './character.js';
import { CharacterController } from './controller.js';
import { WeaponFX } from './weapons.js';
import { loadCharacterGLTF, loadSourceClips } from './assets.js';
import { CHARACTERS } from './config.js';

/**
 * Build stamp shown in the HUD. Bumped on every behavioural change so a stale
 * cached module in the browser is instantly recognisable.
 */
const BUILD = 'r4 · single-action state machine + belly crawl';

/**
 * Boot: renderer + a pure white ground plane, two characters, nothing else.
 * No sky, no props, no terrain – the world is intentionally empty so the
 * characters and their motion are the only thing to look at.
 */
async function boot() {
  const hudRoot = document.getElementById('app');
  const hud = new Hud(hudRoot);
  // static build stamp: bump BUILD on every behavioural change so a stale
  // cached module in the browser is instantly recognisable in the HUD.
  hud.setBuild(BUILD);
  hudRoot.__hud = hud;

  if (!window.WebGL2RenderingContext && !window.WebGLRenderingContext) {
    hud.showError('WebGL is not available in this browser.');
    return;
  }

  const renderer = new THREE.WebGLRenderer({ canvas: hud.canvas, antialias: true, powerPreference: 'high-performance' });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  renderer.setSize(window.innerWidth, window.innerHeight, false);
  renderer.shadowMap.enabled = true;
  renderer.shadowMap.type = THREE.PCFSoftShadowMap;

  const scene = new THREE.Scene();
  scene.background = new THREE.Color(0xffffff);

  const camera = new THREE.PerspectiveCamera(52, window.innerWidth / window.innerHeight, 0.1, 200);
  const cameraRig = new ThirdPersonCamera(camera);

  // ---------------------------------------------------------------- lighting
  const hemi = new THREE.HemisphereLight(0xffffff, 0xffffff, 1.6);
  scene.add(hemi);

  const key = new THREE.DirectionalLight(0xffffff, 2.4);
  key.position.set(4.5, 8.5, 5.5);
  key.castShadow = true;
  key.shadow.mapSize.set(2048, 2048);
  key.shadow.camera.near = 0.5;
  key.shadow.camera.far = 32;
  key.shadow.camera.left = -7;
  key.shadow.camera.right = 7;
  key.shadow.camera.top = 7;
  key.shadow.camera.bottom = -7;
  key.shadow.bias = -0.0009;
  key.shadow.normalBias = 0.02;
  scene.add(key);
  scene.add(key.target);

  const rim = new THREE.DirectionalLight(0xffffff, 0.85);
  rim.position.set(-6, 4.5, -6);
  scene.add(rim);

  // ------------------------------------------------------------- white ground
  const ground = new THREE.Mesh(
    new THREE.PlaneGeometry(160, 160),
    new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.96, metalness: 0.0 }),
  );
  ground.rotation.x = -Math.PI / 2;
  ground.receiveShadow = true;
  ground.name = 'white_ground_plane';
  scene.add(ground);

  // ------------------------------------------------------------------- assets
  hud.setProgress(0.03, 'loading characters…');
  const characterGltfs = {};
  for (let i = 0; i < CHARACTERS.length; i++) {
    const def = CHARACTERS[i];
    characterGltfs[def.id] = await loadCharacterGLTF(def.file);
    hud.setProgress(0.05 + (i + 1) / CHARACTERS.length * 0.08, `loaded ${def.label}`);
  }

  const sourceClips = await loadSourceClips((n, total, id) => {
    hud.setProgress(0.13 + (n / total) * 0.45, `downloading motion clips ${n}/${total} — ${id}`);
  });

  const characters = {};
  for (const def of CHARACTERS) {
    const character = new Character(def, characterGltfs[def.id]);
    scene.add(character.group);
    characters[def.id] = character;
  }

  // Retargeting runs in two passes: capture every bind pose first, then bake.
  const list = Object.values(characters);
  for (const character of list) character.prepareRetargeting(sourceClips);

  const totalSteps = list.length * sourceClips.size;
  let step = 0;
  for (const character of list) {
    character.bakeMotion(sourceClips, () => {
      step += 1;
      hud.setProgress(0.58 + (step / totalSteps) * 0.4, `retargeting motion ${step}/${totalSteps} — ${character.def.label}`);
    });
  }

  const fx = new WeaponFX(scene);
  for (const character of list) character.setupWeapons(scene);

  // -------------------------------------------------------------- controllers
  const controllers = {};
  for (const character of list) {
    const controller = new CharacterController(character, { fx });
    controller.setCameraProvider(cameraRig);
    controllers[character.def.id] = controller;
  }

  const input = new Input(hud.canvas);
  const order = CHARACTERS.map((c) => c.id);
  let activeId = order[0];
  let active = controllers[activeId];

  const setActive = (id) => {
    if (!controllers[id] || id === activeId) return;
    const next = controllers[id];
    next.transferStateFrom(active);
    Object.entries(characters).forEach(([key2, c]) => { c.group.visible = key2 === id; });
    activeId = id;
    active = next;
    cameraRig._initialised = false;
  };
  Object.entries(characters).forEach(([id, c]) => { c.group.visible = id === activeId; });
  active._applyTransform();

  // ------------------------------------------------------------------ resize
  const onResize = () => {
    renderer.setSize(window.innerWidth, window.innerHeight, false);
    camera.aspect = window.innerWidth / window.innerHeight;
    camera.updateProjectionMatrix();
  };
  window.addEventListener('resize', onResize);

  // -------------------------------------------------------------------- loop
  const clock = new THREE.Clock();
  let fpsAccum = 0;
  let fpsFrames = 0;
  let fps = 60;

  const tick = () => {
    const dt = Math.min(clock.getDelta(), 0.05);

    if (input.wasPressed('Tab')) {
      const idx = order.indexOf(activeId);
      setActive(order[(idx + 1) % order.length]);
    }

    cameraRig.update(dt, input, active.character, active);
    active.update(dt, input, cameraRig);
    active.character.update(dt);
    active.character.group.updateMatrixWorld(true);

    // keep the shadow frustum centred on the active character
    key.position.copy(active.position).add(new THREE.Vector3(4.5, 8.5, 5.5));
    key.target.position.copy(active.position);
    key.target.updateMatrixWorld();

    fx.update(dt);

    fpsAccum += dt;
    fpsFrames += 1;
    if (fpsAccum > 0.4) {
      fps = fpsFrames / fpsAccum;
      fpsAccum = 0;
      fpsFrames = 0;
    }
    hud.update(active.getDebugState(), { aiming: active.aiming, fps, locked: input.locked });

    renderer.render(scene, camera);
    input.endFrame();
    requestAnimationFrame(tick);
  };

  hud.setProgress(1, 'ready — click to capture the mouse');
  hud.hideOverlay();
  cameraRig._initialised = false;
  tick();

  // handy for poking at the scene from the console while testing
  window.__characterPrototype = { scene, camera, characters, controllers, sourceClips, renderer };
}

boot().catch((error) => {
  console.error(error);
  const hudTarget = document.getElementById('app');
  const hud = hudTarget.__hud;
  const overlay = document.querySelector('#overlay .label');
  if (hud) hud.showError(String(error && error.stack ? error.stack : error));
  else if (overlay) overlay.textContent = String(error);
});
