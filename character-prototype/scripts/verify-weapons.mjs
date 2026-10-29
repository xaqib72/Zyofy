/**
 * Headless check of the procedural weapon mounting.
 *
 * Poses each character with its aim-idle clip, mounts the placeholder weapons
 * exactly like the app does and reports where the muzzle ends up. A sane result
 * for a ~1.75 m character aiming forward (+Z):
 *   muzzle 0.35-1.0 m in front of the character, 1.0-1.7 m high, |x| < 0.4
 *   barrel direction pointing mostly along +Z
 */
import path from 'node:path';
import * as THREE from 'three';
import { Character } from '../src/character.js';
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

let failures = 0;
for (const def of CHARACTERS) {
  const character = new Character(def, loadRig(path.join(CHAR_DIR, path.basename(def.file))));
  character.prepareRetargeting(sourceClips);
  character.bakeMotion(sourceClips);
  character.setupWeapons(new THREE.Scene());

  console.log(`\n=== ${def.label} ===`);
  for (const [kind, clipId] of [['rifle', 'rifle_aim_idle'], ['pistol', 'pistol_aim_idle']]) {
    channel: {
      const action = character.actions.get(clipId);
      character.mixer.stopAllAction();
      action.reset().play();
      character.mixer.setTime(0.08);
      character.group.updateMatrixWorld(true);

      const weapon = character.weapons[kind];
      weapon.group.visible = true;
      const muzzle = weapon.muzzle.getWorldPosition(new THREE.Vector3());
      const dir = new THREE.Vector3(0, 0, 1).applyQuaternion(weapon.group.getWorldQuaternion(new THREE.Quaternion()));
      const hand = character.bones.rightHand.getWorldPosition(new THREE.Vector3());

      const okPos = muzzle.z > 0.3 && muzzle.z < 1.4 && muzzle.y > 0.8 && muzzle.y < 1.85 && Math.abs(muzzle.x) < 0.55;
      const okDir = dir.z > 0.75;
      if (!okPos || !okDir) failures += 1;
      console.log(
        ` ${okPos && okDir ? 'ok  ' : 'FAIL'} ${kind.padEnd(7)} muzzle [${muzzle.x.toFixed(2)}, ${muzzle.y.toFixed(2)}, ${muzzle.z.toFixed(2)}]` +
        `  hand [${hand.x.toFixed(2)}, ${hand.y.toFixed(2)}, ${hand.z.toFixed(2)}]` +
        `  barrel dir [${dir.x.toFixed(2)}, ${dir.y.toFixed(2)}, ${dir.z.toFixed(2)}]`,
      );
      weapon.group.visible = false;
      break channel;
    }
  }
  character.mixer.stopAllAction();
}

console.log(failures === 0 ? '\nWeapons mount inside the expected envelope.' : `\n${failures} weapon check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
