#!/usr/bin/env node
/**
 * Re-downloads every third-party asset used by the prototype, straight from the
 * upstream repos, and writes them into `public/assets/`.
 *
 *   npm run fetch-assets
 *
 * The files that ship in this repository were produced by *this* script, so the
 * provenance of every byte is reproducible:
 *
 *   Characters  -> github.com/mrdoob/three.js  (examples/models/gltf)
 *                  Soldier.glb   = Mixamo "Vanguard By T. Choonyung"
 *                  Michelle.glb  = Mixamo "Michelle"
 *   Animations  -> github.com/MisterYI/deevid-mixamo-assets (anim/*.glb)
 *                  raw Mixamo motion clips exported for the X-Bot rig
 *
 * Both upstream repos are mirrored copies of Mixamo content that is free to use
 * (see README.md -> Licensing). Only github.com / codeload.github.com are
 * contacted; no login, API token or Mixamo account is required.
 *
 * Requires `git` (for the sparse three.js checkout). Downloads are cached in
 * `.cache/` which is git-ignored, so re-runs are cheap.
 */
import fs from 'node:fs';
import fsp from 'node:fs/promises';
import path from 'node:path';
import zlib from 'node:zlib';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { CLIP_DEFS, CHARACTERS } from '../src/config.js';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const CACHE = path.join(ROOT, '.cache');
const OUT_CHARS = path.join(ROOT, 'public/assets/characters');
const OUT_ANIMS = path.join(ROOT, 'public/assets/animations');

const THREE_REPO = 'https://github.com/mrdoob/three.js';
const ANIM_TARBALL =
  'https://codeload.github.com/MisterYI/deevid-mixamo-assets/tar.gz/refs/heads/main';

/** character file in this repo -> file name inside three.js/examples/models/gltf */
const CHAR_SOURCES = {
  'vanguard_male.glb': 'Soldier.glb',
  'michelle_female.glb': 'Michelle.glb',
};

const log = (...a) => console.log('[fetch-assets]', ...a);

async function download(url, dest) {
  if (fs.existsSync(dest) && fs.statSync(dest).size > 0) {
    log('cached', path.relative(ROOT, dest));
    return dest;
  }
  log('GET', url);
  const res = await fetch(url, { redirect: 'follow' });
  if (!res.ok) throw new Error(`${res.status} ${res.statusText} for ${url}`);
  const buf = Buffer.from(await res.arrayBuffer());
  await fsp.mkdir(path.dirname(dest), { recursive: true });
  await fsp.writeFile(dest, buf);
  log('  ->', path.relative(ROOT, dest), `${(buf.length / 1e6).toFixed(1)} MB`);
  return dest;
}

/* ------------------------------------------------------------------ tarball */

/**
 * Minimal ustar reader: walks the header blocks of a (possibly gzipped) tar and
 * returns a Map of entry-name -> Buffer for the entries we care about.
 */
function readTar(buffer, wanted) {
  const out = new Map();
  let off = 0;
  let longName = null;
  while (off + 512 <= buffer.length) {
    const header = buffer.subarray(off, off + 512);
    if (header.every((b) => b === 0)) break;

    const nameField = header.subarray(0, 100).toString('utf8').replace(/\0.*$/, '');
    const prefix = header.subarray(345, 500).toString('utf8').replace(/\0.*$/, '');
    const sizeField = header
      .subarray(124, 136)
      .toString('utf8')
      .replace(/\0/g, ' ')
      .trim();
    const size = parseInt(sizeField, 8) || 0;
    const type = String.fromCharCode(header[156] || 48);
    const dataStart = off + 512;
    const data = buffer.subarray(dataStart, dataStart + size);
    const fullName = prefix ? `${prefix}/${nameField}` : nameField;

    if (type === 'L') {
      // GNU long name: the real name is the payload of this entry
      longName = data.toString('utf8').replace(/\0.*$/, '');
    } else if (type === '0' || type === '7') {
      const name = longName || fullName;
      longName = null;
      // strip the "<repo>-main/" leading directory
      const rel = name.split('/').slice(1).join('/');
      if (wanted.has(rel)) out.set(rel, Buffer.from(data));
    } else {
      longName = null;
    }
    off = dataStart + Math.ceil(size / 512) * 512;
  }
  return out;
}

async function fetchAnimations() {
  const wanted = new Set(CLIP_DEFS.map((d) => `anim/${d.source}.glb`));
  const tarPath = await download(ANIM_TARBALL, path.join(CACHE, 'deevid-mixamo-assets.tar.gz'));
  log(`unpacking ${wanted.size} clips from the archive…`);
  const raw = zlib.gunzipSync(fs.readFileSync(tarPath));
  const entries = readTar(raw, wanted);

  const missing = [];
  await fsp.mkdir(OUT_ANIMS, { recursive: true });
  for (const def of CLIP_DEFS) {
    const key = `anim/${def.source}.glb`;
    const buf = entries.get(key);
    if (!buf) {
      missing.push(def.source);
      continue;
    }
    const dest = path.join(OUT_ANIMS, `${def.id}.glb`);
    await fsp.writeFile(dest, buf);
  }
  if (missing.length) {
    throw new Error(
      `clip(s) missing from the upstream archive: ${missing.join(', ')}\n` +
        'They were probably renamed upstream — update `source` in src/config.js.',
    );
  }
  log(`wrote ${CLIP_DEFS.length} clips to public/assets/animations/`);
}

/* --------------------------------------------------------------- characters */

async function fetchCharacters() {
  await fsp.mkdir(OUT_CHARS, { recursive: true });
  const pending = Object.keys(CHAR_SOURCES).filter(
    (name) => !fs.existsSync(path.join(OUT_CHARS, name)),
  );
  if (!pending.length) {
    log('characters already present — skipping three.js checkout');
    return;
  }

  // Sparse, blob-less clone: only examples/models/gltf is materialised.
  const checkout = path.join(CACHE, 'three');
  if (!fs.existsSync(path.join(checkout, '.git'))) {
    log('sparse-cloning three.js (blob filter, gltf examples only)…');
    execFileSync(
      'git',
      ['clone', '--depth', '1', '--filter=blob:none', '--sparse', THREE_REPO, checkout],
      { stdio: 'inherit' },
    );
    execFileSync('git', ['sparse-checkout', 'set', 'examples/models/gltf'], {
      cwd: checkout,
      stdio: 'inherit',
    });
  } else {
    log('reusing three.js checkout in .cache/three');
  }

  for (const name of pending) {
    const src = path.join(checkout, 'examples/models/gltf', CHAR_SOURCES[name]);
    if (!fs.existsSync(src)) throw new Error(`not found in three.js checkout: ${src}`);
    await fsp.copyFile(src, path.join(OUT_CHARS, name));
    log(`wrote public/assets/characters/${name} (${(fs.statSync(src).size / 1e6).toFixed(2)} MB)`);
  }
}

/* --------------------------------------------------------------------- main */

async function main() {
  await fsp.mkdir(CACHE, { recursive: true });
  const only = process.argv[2];
  if (only !== 'anims') await fetchCharacters();
  if (only !== 'characters') await fetchAnimations();
  log('done.');
  log('Characters:', CHARACTERS.map((c) => `${c.id} <- ${c.file}`).join(', '));
}

main().catch((err) => {
  console.error('[fetch-assets] FAILED:', err.message);
  process.exit(1);
});
