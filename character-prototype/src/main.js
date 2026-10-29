import * as THREE from 'three';
import { Hud } from './hud.js';
import { Input } from './input.js';
import { CameraRig } from './camera.js';
import { Character } from './character.js';
import { CharacterController } from './controller.js';
import { WeaponFX } from './weapons.js';
import { loadCharacterGLTF, loadSourceClips } from './assets.js';
import { CHARACTERS } from './config.js';
import { ShootingRange } from './range.js';
import { WeaponAudio } from './audio.js';
import { WEAPONS, WEAPON_LIST } from './weaponCatalog.js';
import { loadAllWeaponModels } from './weaponAssets.js';
import { FpsRig } from './fpsRig.js';

/**
 * Build stamp shown in the HUD. Bumped on every behavioural change so a stale
 * cached module in the browser is instantly recognisable.
 */
const BUILD = 'r6 · downloaded weapon models + real FPS rig + CC0 audio';

/**
 * Boot: renderer, a pure white ground plane, the shooting targets, two
 * characters. No sky, no props, no terrain.
 */
async function boot() {
  const hudRoot = document.getElementById('app');
  let hud;
  const weaponSelection = { pending: null };
  hud = new Hud(hudRoot, {
    onSelectWeapon: (id) => { weaponSelection.pending = id; },
  });
  hud.setBuild(BUILD);
  hudRoot.__hud = hud;

  if (!window.WebGL2RenderingContext && !window.WebGLRenderingContext) {
    hud.showError('WebGL is not available in this browser.');
    return;
  }

  // ------------------------------------------------------------------ renderer
  const renderer = new THREE.WebGLRenderer({ antialias: true, powerPreference: 'high-performance' });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
  renderer.setSize(window.innerWidth, window.innerHeight);
  renderer.shadowMap.enabled = true;
  renderer.shadowMap.type = THREE.PCFSoftShadowMap;
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  hud.canvas.replaceWith(renderer.domElement);
  renderer.domElement.id = 'viewport';
  hud.canvas = renderer.domElement;

  const scene = new THREE.Scene();
  scene.background = new THREE.Color(0xffffff);

  const camera = new THREE.PerspectiveCamera(62, window.innerWidth / window.innerHeight, 0.1, 400);
  scene.add(camera); // the first-person viewmodel is parented to the camera
  camera.position.set(0, 1.7, -4);

  // -------------------------------------------------------------------- lights
  const hemi = new THREE.HemisphereLight(0xffffff, 0xffffff, 1.55);
  scene.add(hemi);

  const key = new THREE.DirectionalLight(0xffffff, 2.2);
  key.position.set(5, 9, 6);
  key.castShadow = true;
  key.shadow.mapSize.set(2048, 2048);
  key.shadow.camera.left = -14;
  key.shadow.camera.right = 14;
  key.shadow.camera.top = 14;
  key.shadow.camera.bottom = -14;
  key.shadow.camera.near = 0.5;
  key.shadow.camera.far = 40;
  key.shadow.bias = -0.0012;
  key.shadow.normalBias = 0.02;
  scene.add(key);
  scene.add(key.target);

  const rim = new THREE.DirectionalLight(0xffffff, 0.7);
  rim.position.set(-6, 4, -8);
  scene.add(rim);

  // -------------------------------------------------------------- white ground
  const ground = new THREE.Mesh(
    new THREE.PlaneGeometry(160, 160),
    new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.95, metalness: 0 }),
  );
  ground.rotation.x = -Math.PI / 2;
  ground.receiveShadow = true;
  ground.name = 'white_ground_plane';
  scene.add(ground);

  // ------------------------------------------------------------- shooting range
  const range = new ShootingRange(scene);

  // --------------------------------------------------------------------- assets
  hud.setProgress(0.03, 'loading characters…');
  const characterGltfs = {};
  for (let i = 0; i < CHARACTERS.length; i += 1) {
    const def = CHARACTERS[i];
    characterGltfs[def.id] = await loadCharacterGLTF(def.file);
    hud.setProgress(0.05 + ((i + 1) / CHARACTERS.length) * 0.08, `loaded ${def.label}`);
  }

  const sourceClips = await loadSourceClips((n, total, id) => {
    hud.setProgress(0.13 + (n / total) * 0.42, `downloading motion clips ${n}/${total} — ${id}`);
  });

  const characters = {};
  for (const def of CHARACTERS) {
    const character = new Character(def, characterGltfs[def.id]);
    scene.add(character.group);
    characters[def.id] = character;
  }

  const list = Object.values(characters);
  hud.setProgress(0.56, 'capturing bind poses…');
  for (const character of list) character.prepareRetargeting(sourceClips);

  const totalSteps = list.length * sourceClips.size;
  let step = 0;
  for (const character of list) {
    character.bakeMotion(sourceClips, () => {
      step += 1;
      hud.setProgress(0.58 + (step / totalSteps) * 0.38, `retargeting motion ${step}/${totalSteps}`);
    });
  }

  // ------------------------------------------------- real weapon GLB models
  hud.setProgress(0.96, 'loading weapon models…');
  const assetReport = await loadAllWeaponModels(WEAPON_LIST, (n, total, def) => {
    hud.setProgress(0.96 + (n / total) * 0.02, `weapon model ${n}/${total} — ${def.glb}`);
  });

  const fx = new WeaponFX(scene);
  for (const character of list) character.setupWeapons(scene);

  // ------------------------------------------------- first-person viewmodel
  // One shared rig (the downloaded, rigged AK-47 + hands asset) is reused for
  // every weapon: it holds the active weapon on its `gun` bone and plays its own
  // real `fire` / `reload_empty` clips.
  const fpsRig = new FpsRig();
  await fpsRig.load('assets/fps/ak47_hands.glb', { length: 0.88 });
  fpsRig.gripOffset.set(0.16, -0.15, -0.70);
  fpsRig.placeForCamera();
  fpsRig.refreshBase();
  camera.add(fpsRig.group);

  const audio = new WeaponAudio();
  const audioReport = await audio.loadSamples((n, total, name) => {
    hud.setProgress(0.98 + (n / total) * 0.015, `sound ${n}/${total} — ${name}`);
  });

  // -------------------------------------------------------------- controllers
  const cameraRig = new CameraRig(camera);
  const controllers = {};
  let activeCharacterRef = null;
  const activeCharacter = () => activeCharacterRef;
  for (const character of list) {
    const controller = new CharacterController(character, {
      fx,
      hitTest: (origin, dir, def) => range.hitTest(origin, dir, def),
      aimProbe: (origin, dir, maxDistance) => range.probe(origin, dir, maxDistance),
      muzzleProvider: (out) => (cameraRig.mode === 'fps'
        ? fpsRig.getMuzzleWorld(out)
        : character.getMuzzleWorld(out)),
      onEvent: (type, payload) => {
        if (type === 'shot') {
          audio.play(WEAPONS[payload.weapon]);
          if (character === activeCharacter()) fpsRig.shoot();
        } else if (type === 'impact') {
          if (character === activeCharacter()) audio.impact(true);
        } else if (type === 'melee') {
          audio.swoosh(!!payload.hit);
          if (character === activeCharacter()) fpsRig.play('idle', 0.1);
        } else if (type === 'reloading') {
          audio.click(WEAPONS[payload.weapon].reloadStyle);
          if (character === activeCharacter()) fpsRig.reload(payload.duration);
        } else if (type === 'dryReload') {
          audio.click('dry');
        } else if (type === 'reloaded') {
          audio.click('mag');
        } else if (type === 'weapon') {
          audio.equip();
        }
      },
    });
    controller.setCameraProvider(cameraRig);
    controllers[character.def.id] = controller;
  }

  const input = new Input(renderer.domElement);
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
    activeCharacterRef = active.character;
    cameraRig._initialised = false;
  };
  Object.entries(characters).forEach(([id, c]) => { c.group.visible = id === activeId; });
  activeCharacterRef = active.character;
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

    // ---- global inputs
    if (input.wasPressed('Tab')) {
      const idx = order.indexOf(activeId);
      setActive(order[(idx + 1) % order.length]);
    }
    if (input.wasPressed('KeyV')) {
      cameraRig.toggle();
      hud.setProgress(1, cameraRig.mode === 'fps' ? 'first person' : 'third person');
    }
    if (input.wasPressed('KeyT')) {
      range.reset();
      fx.decals.forEach((d) => scene.remove(d.object));
      fx.decals.length = 0;
    }
    if (weaponSelection.pending) {
      active.equip(weaponSelection.pending);
      weaponSelection.pending = null;
    }
    if (input.locked) audio.resume();

    cameraRig.update(dt, input, active.character, active);
    active.update(dt, input, cameraRig);
    active.character.update(dt);
    active.character.group.updateMatrixWorld(true);
    // In first person the body is hidden (its hand-welded weapon and arms would
    // sit between the eye and the sights); the camera-parented viewmodel with
    // its own arms takes over. Nothing about the character's state changes.
    for (const character of list) {
      const isActive = character === active.character;
      character.group.visible = isActive ? cameraRig.mode !== 'fps' : false;
      character.updateHandWeaponVisibility(isActive ? cameraRig.mode : 'tps');
    }

    // first-person viewmodel: the shared downloaded rig holds the active weapon
    const activeDef = WEAPONS[active.weapon];
    if (activeDef) fpsRig.setWeapon(activeDef);
    fpsRig.group.visible = cameraRig.mode === 'fps';
    if (cameraRig.mode === 'fps') {
      fpsRig.update(dt, { recoil: active.recoilPitch || 0, aiming: active.aiming });
    }

    // keep the shadow frustum centred on the active character
    key.position.copy(active.position).add(new THREE.Vector3(4.5, 8.5, 5.5));
    key.target.position.copy(active.position);
    key.target.updateMatrixWorld();

    range.update(dt);
    fx.update(dt);

    fpsAccum += dt;
    fpsFrames += 1;
    if (fpsAccum > 0.4) {
      fps = fpsFrames / fpsAccum;
      fpsAccum = 0;
      fpsFrames = 0;
    }

    hud.update(active.getDebugState(), {
      aiming: active.aiming,
      fps,
      locked: input.locked,
      range: range.getDebugState(),
      scoped: cameraRig.scoped,
    });

    renderer.render(scene, camera);
    input.endFrame();
    requestAnimationFrame(tick);
  };

  hud.setProgress(1, 'ready — click to capture the mouse');
  hud.hideOverlay();
  cameraRig._initialised = false;
  tick();

  // handy for poking at the scene from the console while testing
  window.__characterPrototype = {
    scene, camera, cameraRig, characters, controllers, sourceClips, renderer, range, audio, input, hud,
    fpsRig, assetReport, audioReport,
    get active() { return active; },
    get activeId() { return activeId; },
  };
}

boot().catch((error) => {
  console.error(error);
  const hud = document.getElementById('app') && document.getElementById('app').__hud;
  if (hud) hud.showError(String(error && error.stack ? error.stack : error));
});
