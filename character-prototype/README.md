# Character & Movement Prototype

A browser-based test bed for **third-person character movement and animation only** — the
vertical slice a survival shooter needs before any world, missions, or inventory exist.

Two realistic, Mixamo-rigged characters (one male, one female) with a full locomotion /
posture / combat animation set, driven by a proper animation state machine and a
third-person camera. The scene contains nothing but a flat white ground plane.

> **Scope:** no game world, no props, no sky, no menus, no multiplayer. Just characters
> moving around a white floor, so animation and control quality can be judged in isolation.

![stack](https://img.shields.io/badge/three.js-r180-black) ![build](https://img.shields.io/badge/vite-7-black) ![assets](https://img.shields.io/badge/assets-Mixamo%20%C2%B7%2042%20GLB-black)

---

## Quick start

```bash
npm install
npm run dev        # http://localhost:5173  (binds 0.0.0.0)
```

Click the canvas once to capture the mouse (pointer lock), then press **Escape** to release it.

```bash
npm run build      # static bundle in dist/
npm run preview    # serve dist/
npm run fetch-assets   # re-download the original GLBs from upstream (optional)
npm run verify-all     # headless checks, no browser needed
```

Requires Node ≥ 20 (developed on Node 22). No GPU/WebGL needed for the verification
scripts; a WebGL2 browser is needed to actually view the prototype.

---

## r4 — state machine rewrite and the belly crawl

### Why animations played twice (root cause, not a symptom fix)

Three real defects, all now covered by `npm run verify-controls`:

1. **Leaked cross-fade weights.** `action.crossFadeFrom()` was used to blend.
   When a clip was replaced *while it was still fading*, three.js' internal
   weight interpolant froze at a fraction (0.32, 0.62, …) and that action kept
   contributing a slice of its pose **forever** — so several animations played on
   one skeleton at once. Blending is now explicit (`Character._blend`): weights
   are written directly, the outgoing action is `stop()`ped when the blend
   finishes, and every frame asserts that nothing but the current action (plus
   its two blend partners) is running.
2. **One-shot clips looped.** `setLoop(THREE.LoopOnce, Infinity)` means *repeat
   forever*; reload / hit / get-up / prone-down visibly restarted. Now
   `LoopOnce` gets `repetitions = 1`.
3. **No state guards.** Jump could be pressed while prone, posture could change
   mid-air, and a held trigger fired every frame. There is now one explicit
   state machine: a single `action` slot, `busy` locking, edge-triggered inputs
   (`e.repeat` ignored), fire modes (`auto` reads the held button, `semi`/`burst`
   consume the press edge), and posture changes refused while airborne or busy.

Also fixed: speed tables were duplicated (one in `config.js`, one in the
controller) and had drifted, which caused foot sliding. Playback rate is now
derived from each clip's baked `naturalSpeed` only.

### Prone: belly crawl

The old forward crawl used Mixamo's `Crawling` clip, which keeps the hips at
**0.44 m** — a hands-and-knees baby crawl. Forward movement now uses
`Zombie_Crawl`, a genuine flat drag (hips 0.10–0.26 m in the source data, knees
never leaving the floor), where the elbows and forearms pull the body along.
Sideways prone movement uses `Prone_Left_Turn` / `Prone_Right_Turn`, and
standing↔prone uses a real descent (`Burpee_Start`) and get-up (`Kip_Up`).

Measured on the retargeted male rig by the automated test:

| Movement | clip | hips max | chest max | knees | lowest bone |
| --- | --- | --- | --- | --- | --- |
| crawl forward | `crawl_fwd` | 0.27 m | 0.46 m | 0.11 m | −0.02 m |
| crawl backward | `crawl_back` | 0.34 m | 0.42 m | 0.14 m | 0.00 m |
| crawl left | `crawl_left` | 0.32 m | 0.42 m | 0.16 m | 0.01 m |
| crawl right | `crawl_right` | 0.19 m | 0.28 m | 0.11 m | 0.00 m |

The test also asserts that the body never rises into a kneel, never plays a
standing or get-up clip mid-crawl, and that pressing Space while prone does
nothing.

**Limitation:** the free Mixamo mirror has no dedicated military belly-crawl
loop. `Zombie_Crawl` is the only flat, forward-translating crawl in the 886-clip
library and it reads as a drag-crawl (both hands stay near the floor, arms pull,
legs trail), but it is mocap named for a zombie drag, not authored for a
soldier. It was chosen over the alternative — a hands-and-knees crawl — because
staying flat is the explicit requirement.

## Controls

| Input | Action |
| --- | --- |
| **W A S D** | move (camera-relative, 8-way) |
| **Mouse** | look / aim |
| **Shift** | sprint |
| **Alt** | walk (force walk speed) |
| **RMB** (hold) | aim down sights — body turns to camera, strafe clips |
| **LMB** | fire (recoil, muzzle flash, spread, projectile trace) |
| **R** | reload (per weapon, respects magazine + reserve) |
| **Space** | jump (with air pose + landing) |
| **Ctrl** | crouch toggle → crouch-walk / crouch-aim |
| **Z** | prone toggle → smooth lie-down, belly crawl, get up |
| **1 / 2** | pistol / rifle |
| **Tab** | switch between the male and female character (state is transferred) |
| **H / J** | take light / heavy damage |
| **K / G** | die / get up (revive) |
| **Escape** | release the mouse |

The HUD shows the live state (posture, speed, weapon, ammo, clip name, current character)
and an FPS counter; the state panel is what the automated controller test asserts against.

---

## What is implemented

**Animation.** 40 retargeted Mixamo clips, all blended through one `AnimationMixer` per
character with 0.12–0.22 s cross-fades:

* idle / breathing, walk, run, sprint (forward, back, left, right + diagonals)
* jump take-off / falling loop / landing, plus a gravity-based air state
* crouch idle, crouch-walk in 4 directions, crouch→stand transition
* prone, crawl forward/back, kip-up get-up
* pistol & rifle aim idles, aim-walk / aim-run, strafe-aim, crouch-aim
* firing poses for pistol and rifle, recoil kick, muzzle flash, reload per posture
* light/heavy hit reactions, two death directions, get-up/recovery
* body turns to face the camera while aiming or strafing; free movement faces the
  movement direction; one-shot clips lock the state machine until they finish

**Movement.** Accelerated ground movement with posture-dependent speed tables, sprint,
crouch (1.25 m/s), crawl (0.55 m/s), aim-walk/aim-run caps, gravity + jump impulse,
coyote-free simple ground clamp. Clip playback rate is time-scaled by
`speed / naturalSpeed` so foot contact matches actual velocity.

**Camera.** Spring-arm third-person orbit camera: pointer-lock yaw/pitch, per-posture
distance & shoulder offset (wider when sprinting, tighter when aiming, low and close
when prone), head-bone target with smoothing, recoil pitch and strafe roll.

**Weapons.** Placeholder pistol and rifle assembled from primitives, attached to the hand
bone and re-aligned from the aim pose so no per-character tuning is needed. No models were
downloaded for these — the brief allows placeholders.

**Environment.** A 160 m white `MeshStandardMaterial` plane, white background, one
hemisphere + one shadow-casting key + one rim light. No sky, no geometry, no props.

---

## Architecture

```
index.html            mounts #app, loads src/main.js
src/config.js         40 clip definitions (source name, loop, natural ground speed),
                      character definitions, tuning constants, weapon specs, bone names
src/retarget.js       skeleton-agnostic retargeter (see below) + clip baking
src/assets.js         GLTFLoader wrappers for characters and clips
src/character.js      Character: rig wrapper, retarget prep, mixer, weapons, play()
src/controller.js     CharacterController: posture state machine, speeds, jumping,
                      aiming/shooting/reload/damage/death, animation selection
src/camera.js         ThirdPersonCamera
src/input.js          keyboard/pointer-lock mouse state
src/main.js           renderer, lights, ground, boot + rAF loop
src/hud.js            loading overlay, state panel, controls legend, crosshair
scripts/              headless verification (below)
```

### Retargeting

The two characters and the animation library are all Mixamo `mixamorig:*` rigs, but their
**bind poses differ** (the male GLB is authored facing −Z, the female faces +Z, and their
rest limb orientations and proportions differ). Retargeting therefore ignores bone-local
space and works in world space per frame:

```
C           = hips_worldQ(target, bind) · hips_worldQ(source, bind)⁻¹      (rigid offset)
delta       = srcAnimWorldQ · srcRestWorldQ⁻¹                              (motion in world)
targetWorldQ = C · delta · C⁻¹ · targetRestWorldQ
localQ       = parentWorldQ⁻¹ · targetWorldQ
```

Bind poses are captured before any clip is sampled, missing bones inherit
`parentWorldQ · restQ`, hips keep vertical motion only (root XZ drift is zeroed because the
controller drives translation), and hip height is scaled by the ratio of leg lengths.
Clips are baked to 30 fps keyframes once at load and optimised. Measured limb-direction
fidelity against the source motion: **1.2–1.5° mean on the male** and **~5.3° mean on the
female** (the female residual is bone-length/proportion difference, not error in the math).

Each character's `gltf.scene` is wrapped in a `rig_<id>` node carrying a per-character
`modelYaw`, so the rest of the codebase can assume "forward = +Z".

---

## Headless verification

No browser automation is available in the build environment (`cdn.playwright.dev` and
Chromium mirrors are unreachable), so behaviour is verified in Node against the real GLB
files, using a small GLB reader (`scripts/lib/mini-gltf.mjs`) that reproduces GLTFLoader's
node-name sanitisation:

| Command | What it proves |
| --- | --- |
| `npm run verify-rig` | all 40 clips retarget onto both rigs; head/hip heights and durations sane |
| `npm run verify-fidelity` | retargeted limb directions match the source motion (mean error per clip) |
| `npm run verify-weapons` | pistol & rifle muzzles land in front of the hand, barrels point forward |
| `npm run verify-controls` | 56 assertions: scripted walkthrough, single-action invariant, belly-crawl heights, mutually exclusive states, fire modes, ammunition accounting |
| `npm run verify-browser` | loads the GLBs with the **real `GLTFLoader`** the page uses (images stripped, since Node has no DOM) and asserts prepareRetarget / bakeClip / mixer binding for all 40 clips on both rigs |

Current results: 40/40 clips retarget, worst clip fidelity 5.3° mean, all 4 weapon mounts
inside tolerance, the controller resolves a valid clip on every frame of the walkthrough
(35 distinct clips exercised), and all 40 clips bake + bind through the real GLTFLoader on
both rigs.

`verify-browser` exists because of a real boot failure: `GLTFLoader` only creates `THREE.Bone`
for nodes referenced as *skin joints*. The animation GLBs are bare skeletons with no `skins`
array, so their joints load as plain `Object3D`s and a bone-only traversal found no
`mixamorig:Hips` on the source rig — the page aborted with *"Retarget: hip bone … missing"*.
`rigNodes()` now falls back to all non-mesh nodes, and this test reproduces the browser's
object graph so the same class of bug fails CI instead of the page.

These tests cannot confirm *visual* quality or WebGL rendering — that needs a human looking
at the page.

---

## Assets, provenance and licensing

Every binary in `public/assets/` is downloaded from a public GitHub mirror by
`scripts/fetch-assets.mjs`; nothing is hot-linked and nothing is hand-made.

| Local file | Upstream | Original | License |
| --- | --- | --- | --- |
| `characters/vanguard_male.glb` | [mrdoob/three.js · examples/models/gltf/Soldier.glb](https://github.com/mrdoob/three.js/blob/dev/examples/models/gltf/Soldier.glb) | Mixamo **"Vanguard By T. Choonyung"** | Mixamo asset — royalty-free use |
| `characters/michelle_female.glb` | [mrdoob/three.js · examples/models/gltf/Michelle.glb](https://github.com/mrdoob/three.js/blob/dev/examples/models/gltf/Michelle.glb) | Mixamo **"Michelle"** | Mixamo asset — royalty-free use |
| `animations/*.glb` (40 files) | [MisterYI/deevid-mixamo-assets · anim/](https://github.com/MisterYI/deevid-mixamo-assets) | raw Mixamo motion clips, X-Bot rig | Mixamo asset — royalty-free use |

Notes on licensing and honesty:

* Mixamo content is licensed for use in commercial and non-commercial projects without
  attribution under the [Mixamo terms](https://helpx.adobe.com/creative-cloud/faq/mixamo-faq.html);
  the characters/animation *content* is Adobe's, the *use* here is permitted. Both mirrors
  redistribute it openly, and three.js ships the two character GLBs in its own
  MIT-licensed repository for exactly this purpose.
* The clip names in `src/config.js` (`source:` field) are the original Mixamo names, so each
  `.glb` stays traceable to its upstream file. `fetch-assets.mjs` re-downloads them by name.
* Weapons are procedural primitives — no third-party models are involved, so no extra
  license applies.
* Runtime dependencies: [three.js](https://github.com/mrdoob/three.js) (MIT) and
  [Vite](https://github.com/vitejs/vite) (MIT).

### Why these assets

The build sandbox had no Blender, no `ffmpeg`, no `git-lfs`, and no Mixamo account (bulk
Mixamo downloaders all need a logged-in session), so code-only conversion pipelines were not
possible. Instead the assets are the ones already published as ready-to-use GLB in public
repositories. Several candidates were rejected: `Orillusion/assets` and
`ir-engine/ir-engine-assets-basic` are only LFS pointers, and the avatars in
`never-wet/never-wet.github.io` are low-poly/stylised — not realistic enough for the brief.

## Known limitations

1. **No visual smoke test in CI.** Rendering and "does it feel right" can only be judged by
   opening the page. The automated checks cover math, clip coverage and bone attachment.
2. **Female retarget residual ~5°.** Bone proportions differ between the X-Bot source rig and
   Michelle, so limb *directions* match but segment *shapes* differ slightly. A hand-authored
   pose set per character would be needed to remove this entirely.
3. **Some Mixamo clips are mislabelled upstream.** Two files whose names suggest prone poses
   are actually upright; the prone set therefore uses `Prone_Backwards_Stop` as the prone
   idle, `Crawling` / `Crawling_Backwards` for crawl, and `Kip_Up` to get up. Similar
   substitutions: `Crouched_Sneaking_Right` is the only usable crouch-right clip,
   `Pistol_Strafe` stands in for forward pistol aim-walk (no such clip exists), and
   `Gunplay` is used as the looping pistol firing pose.
4. **Root motion is stripped.** Clips keep vertical hip motion but zero XZ drift; translation
   is driven by the controller so speed changes don't fight the animation.
5. **No IK, no foot planting, no ragdoll.** Deaths are clip-driven, not physics.
6. **Single rig pair.** Only the two bundled characters are supported; adding another Mixamo
   GLB means adding a `CHARACTERS` entry (and, if it faces another way, a `modelYaw`).
