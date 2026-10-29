/** Measures every clip in the pack: duration, hip height range, ground travel. */
import fs from 'node:fs';
import path from 'node:path';
import * as THREE from 'three';
import { parseGLB } from './lib/glb-browser.mjs';

const dir = process.argv[2];
const out = process.argv[3] || '/tmp/clips.csv';
const files = fs.readdirSync(dir).filter((f) => f.endsWith('.glb')).sort();
const rows = ['name,dur,hipYmin,hipYmax,travel,natSpeed'];
for (const f of files) {
  try {
    const g = await parseGLB(path.join(dir, f));
    const root = g.scene; const clip = g.animations?.[0];
    if (!clip) continue;
    let hips = null;
    root.traverse((o) => { if (!hips && /hips/i.test(o.name)) hips = o; });
    if (!hips) continue;
    const mixer = new THREE.AnimationMixer(root);
    mixer.clipAction(clip, root).reset().play();
    let minY = Infinity; let maxY = -Infinity; let travel = 0; let prev = null;
    for (let i = 0; i <= 24; i += 1) {
      mixer.setTime(Math.min((clip.duration * i) / 24, clip.duration - 1e-3));
      root.updateMatrixWorld(true);
      const p = hips.getWorldPosition(new THREE.Vector3());
      minY = Math.min(minY, p.y); maxY = Math.max(maxY, p.y);
      if (prev) travel += Math.hypot(p.x - prev.x, p.z - prev.z);
      prev = p;
    }
    rows.push([f.replace('.glb', ''), clip.duration.toFixed(3), minY.toFixed(3), maxY.toFixed(3),
      travel.toFixed(3), (travel / clip.duration).toFixed(3)].join(','));
  } catch {
    // skip unreadable clips
  }
}
fs.writeFileSync(out, `${rows.join('\n')}\n`);
console.log(`wrote ${rows.length - 1} clips to ${out}`);
