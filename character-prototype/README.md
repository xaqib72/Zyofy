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

## r5 — FPS/TPS, shooting range, 30-weapon system

### Why the previous round did not settle it

`r4` fixed real bugs (leaked cross-fade weights, `LoopOnce` looping forever,
missing state guards) and every headless assertion passed - but the *visual*
result was never verified, because there is no browser in this environment. That
is now fixed at the tooling level: `scripts/render-views.mjs` and
`scripts/render-poses.mjs` render the **real skinned meshes**, produced by the
real GLTFLoader + retargeter + controller, into PNGs with a software rasteriser.
Every visual claim below was checked by looking at those renders.

Two defects the renders exposed that the assertions could not:

* In first person the weapon was **not shouldered** (the body followed the
  camera but the weapon stayed in the hip-fire pose, so the barrel pointed
  across the screen). Fixed: the aim family of clips is now the "weapon ready"
  pose in FPS.
* The first-person weapon and hands were rendered from an eye that sits *behind*
  the right hand, which is geometrically impossible to frame. Fixed with a
  camera-parented viewmodel.

### First person / third person

| | third person | first person |
| --- | --- | --- |
| toggle | **V** | **V** |
| camera | spring-arm orbit, over-the-shoulder aiming, closer and lower when prone, ground-clamped | eye level, parked ahead of the face (head stays behind the near plane) |
| weapon | welded to the right hand, aligned per weapon family | camera-parented viewmodel with its own arms, no stock geometry (it is in the shoulder) |
| body | visible | hidden (its hand-welded weapon would sit between eye and sights) |
| aiming | camera ray | camera ray |

State (position, posture, weapon, ammunition, hits) is untouched by the toggle -
`verify-controls` asserts position/weapon/ammo survive a switch. In first person
the body turns instantly to the look direction and uses the aim clips, so the
weapon always points down the sights; the test asserts the barrel stays within
16 degrees of the view for rifle, sniper, pistol, revolver, shotgun and LMG.

### Shooting range

Six concentric bullseyes (8, 15, 25, 40, 60, 90 m) and three humanoid
silhouettes (12, 30, 55 m) on thin posts, plus floor distance rings. Nothing
else exists in the world. Hitscan comes from the camera along the real aim
direction; damage falls off past 60 % of the weapon's effective range; impacts
leave a mark that fades; hit targets flash. HUD shows hit count, last hit and
damage. Verified in both camera modes by the test suite.

### Weapon system — 30 distinct weapons

| category | weapons |
| --- | --- |
| pistols | Glock 17, M9, Desert Eagle .50, USP-S (suppressed) |
| revolvers | Colt Python, S&W 629 |
| SMGs | MP5, MP7, Vector (burst), Uzi, MP5SD (suppressed) |
| assault rifles | AK-47, M4A1, SCAR-L, AK-74U |
| battle rifles | FN FAL, SCAR-H |
| marksman | M14 EBR, SVD |
| snipers | AWP (bolt), M24 (bolt), SR-25 (semi) |
| shotguns | Remington 870 (pump), SPAS-12 (pump), Benelli M4 (semi) |
| machine guns | M249 SAW, PKM |
| melee | combat knife, tactical knife, machete |

Every weapon has its own fire mode, rate, recoil, spread, magazine, reserve,
reload time, damage, range, draw animation and body mechanics. `verify-weapons`
asserts the 30 models are **geometrically distinct** (bounding box + mesh count +
moving-part set are unique), that no weapon is a renamed duplicate, and that all
30 mount correctly on both rigs.

Weapon-specific behaviour that is actually implemented:

* **Trigger semantics** - `auto` reads the held button, `semi`/`bolt`/`pump`
  consume the press edge, `burst` fires its burst then stops.
* **Bolt-action** - one shot, then a forced cycle; the bolt handle lifts and
  travels back on the model before the next round.
* **Pump-action** - one shot, then the forend racks back and forward.
* **Revolver** - cylinder swings out and spins during the reload, and indexes a
  sixth of a turn per shot.
* **Magazine-fed** - the magazine drops out of the well and seats again during
  the reload; the slide kicks on every shot.
* **Machine guns** - sustained fire with an elevated recoil impulse and belt box.
* **Melee** - no bullets; a stab/slash clip plays, the swing ray is aimed at the
  point the crosshair is on, and the impact registers on the target.

### Weapon selection

`1`–`9` quick-pick the first nine, **Q** or the mouse wheel cycles all 30, and a
compact list on the right of the HUD selects any of them by click. **E** refills
every magazine and reserve. **T** resets the targets and hit counter. The
selected character and camera mode are preserved.

### Assets, as they stood in r5

The brief asks for downloaded weapon models, animation packs and audio. What was
actually obtained and what was not:

| asset | status |
| --- | --- |
| weapon **models** | **not obtainable** - no reachable source offers freely-licensed firearm GLBs without an authenticated download (Sketchfab requires login, the CC0 packs mirrored on GitHub contain no firearms). Built procedurally from per-weapon parameters instead of shipping one model under 30 names. |
| weapon **audio** | **not obtainable** (Freesound needs an account, the free SFX sites are paid or hotlink-protected). Synthesised per weapon class with the Web Audio API (`src/audio.js`): suppressed weapons are a short filtered thump, shotguns a heavy burst with a long tail, snipers a crack, knives a whoosh. |
| **character** animations | obtained - 9 additional Mixamo clips (draw long/short, holster, stab, slash, weapon idle) retargeted through the same pipeline, 49 clips total |
| **characters, ground** | unchanged - Vanguard + Michelle on the same white plane |

Two Mixamo clips were rejected after measuring them rather than trusting their
names: `Rifle_Pull_Out` lifts the hips 2.4 m (it is an airborne action) and
`Knife_Idle` bobs a metre vertically. They were replaced with
`Grab_Rifle_From_Back`, `Rifle_Aim_To_Down` and `Rifle_Idle`.

### How the visuals were checked

```
node scripts/render-poses.mjs            # prone / crawl / crouch / jump sheets, both rigs
node scripts/render-views.mjs            # the actual TPS and FPS frames + a 30-weapon sheet
open /home/user/renders/*.png
```

The renders are real geometry with a flat shader; they do not reproduce lighting,
shadows or faces, but they do show pose, framing and whether the right mesh is
where it should be.

## r6 — real downloaded assets replace the placeholders

The r5 build was honest about its gaps: the 30 weapons were procedural
primitives, the first-person arms were stand-in cylinders, gunshots were
synthesised noise. **All three are now actual downloaded assets**, with the
sources and licences recorded in [`public/assets/ASSETS.md`](public/assets/ASSETS.md).

| what | before (r5) | now (r6) |
| --- | --- | --- |
| weapon models | 30 procedural primitive builds | **19 downloaded GLBs** — Quaternius "Ultimate Guns" (CC0) plus the Remington 870 MCS and Browning M2 from Sketchfab (CC BY 4.0) |
| first person | procedural cylinder arms, camera-parented | **`ak47_hands.glb`** — a rigged FPS arms + weapon asset with its own `idle` / `fire` / `reload_empty` clips (kursat_sokmen, CC BY 4.0) |
| gunshot / reload / impact audio | Web Audio noise synthesis | **12 recorded CC0 samples** from the OpenSpades free pak and its documented upstream sources |
| muzzle flash / tracers / impacts | untextured quads and line segments | **Kenney Particle Pack atlas** (CC0) — muzzle cells, sparks, tracer streaks, scorch marks |

### How a downloaded weapon becomes a held weapon (`src/weaponAssets.js`)

No hand tuning per model, and no procedural fallback geometry at all:

1. **long axis → +Z** by measuring the bounding box;
2. **scale to real length** from `modelLength` in the catalogue;
3. **which end is the muzzle?** the adapter profiles how much geometry hangs
   *below the bore* at each end - a receiver, grip, trigger guard and stock all
   hang below, a bare barrel does not. The `flip` override exists only for
   silhouettes the test cannot separate (the revolver), and every override was
   checked against the rendered weapon sheet;
4. **detached junk is pruned** by union-finding touching mesh bounds (the 870 MCS
   ships a second copy of its geometry 60 units behind the real one);
5. **grip at the origin**, muzzle anchor at the bore;
6. **moving parts are discovered** from the asset's own node names - the 870's
   `ActionBar_low` becomes the pump, its `MagazineLoadPort_low` sits where rounds
   are fed.

`verify-weapons` asserts all of it: 114 checks, including that the 19 meshes are
geometrically distinct, that the thin end ends up forward on every model, that
every weapon mounts on both rigs, and that the assets' own parts animate.

### First person is a real asset

The AK-47 model is an unusual find: the *gun is a separate skinned mesh driven by
its own bones* (`gun`, `slider`, `magazine`, `trigger`, `gun_end`), inside a rigged
pair of hands with `idle`, `fire` and `reload_empty` clips. `src/fpsRig.js` uses
that:

* equip the AK → its own gun mesh is shown;
* equip anything else → the gun mesh is hidden and **the downloaded weapon model
  is parented to the `gun` bone**, scaled so the hands keep their grip spacing,
  with its local rotation *solved* from the bone's world orientation (the asset's
  bone space carries a 90° rig convention, which a hard-coded rotation gets
  wrong);
* firing plays the asset's `fire` clip, reloading plays `reload_empty` stretched
  to the weapon's reload time;
* the barrel is aligned to the camera axis by measuring the skinned gun mesh -
  `Box3.setFromObject` reads *bind-pose* geometry for skinned meshes, so the rig
  refreshes `skeleton.boneMatrices` before measuring. That bug alone made the
  first three attempts at FPS framing look wrong.

### Effects are functional, not decorative

`WeaponFX` now draws the atlas cells: the muzzle cell for the weapon's class
(long flash for rifles, round for pistols/shotguns, small for SMGs), a glow
sprite, a `trace_07` streak stretched along the **real** shot direction, spark
cells plus a scorch cell at the **real** hit point returned by the hitscan, and a
point light for the flash. The tracer starts at the muzzle that actually fired -
in first person that is the rig's muzzle bone, in third person the hand weapon's.

### Scope

Scoped sniper rifles (AWP, marksman rifle) now genuinely zoom when aiming in
first person: the camera FOV collapses to 19° and an optical overlay is raised.
This is the one place where a *downloaded* asset could not be used - no CC0
reticle/sight-picture pack was reachable - so the overlay is CSS drawn over the
real zoomed view. It is not a fake: the shot goes down the same camera axis the
sight is centred on.

### Audio

`src/audio.js` decodes the 12 Opus files at boot and plays the recorded shot,
reload, pump, dry-fire, melee-impact and bullet-impact for the right weapon
class. `WeaponAudio.status()` reports whether recorded samples or the old
synthesiser are live; if a browser refuses Opus the game falls back rather than
going silent.

## r7 — fix pass: weapon handling, aiming, animation

This round adds no new weapons and no new systems. It inspects the running
project, finds the root causes of the reported defects and repairs them.

### 1. A baked frame of the *first* pose landed on the end of every clip

`bakeClip` sampled the last frame at `t = clip.duration`. `AnimationMixer.setTime`
treats that as past the end of a `LoopOnce` clip and wraps to `t = 0`, so the final
baked frame held the *starting* pose. `prone_down` (1.67 s) therefore ended with one
frame of the character standing — a 57 cm hip snap — and every looping clip wrapped
back to a mismatched pose. That single line is what "prone jerks" was: the sampler
now clamps to `duration - 1 ms`, and looping clips are baked with their last frame
copied from frame 0 so a wrap is seamless.

### 2. The body was spinning inside the clips

Mixamo's motion carries the actor's own turning on the hips: the belly crawl turns
the pelvis 268°, standing up from prone 358°, and the rifle-fire clip 46°. The
controller also owns the body's facing, so those two fought each other. `bakeClip`
now pins the hips' vertical-axis rotation to the clip's own first frame (pitch and
roll are kept, which is what lying flat needs) and republishes the corrected world
rotation so the spine, arms and legs are solved against it. Clips that *should*
turn — melee swings, deaths — opt out with `keepTurn: true` in `CLIP_DEFS`.
Measured hip-yaw travel: `prone_stand` 358° → 0°, `crawl_fwd` 268° → 0°,
`rifle_fire` 46° → 0°.

### 3. Armed idle was the *unarmed* idle

`_locomotionClip` fell through to `idle`, `walk_*` and `run_*` whenever the player
was not aiming, so the rifle stayed welded to a hand that was swinging free: the
barrel measured **111° off the body's forward axis**, the muzzle hung at 0.53 m and
the support hand was **0.95 m** from the foregrip. There is now a real two-handed
carry set — `Rifle_Idle`, `Walk_With_Rifle`, `Backwards_Rifle_Walk`,
`Rifle_Start_Run` from the same Mixamo pack — plus `Knife_Idle` for the blade
(previously the knife idle was literally a rifle idle). Armed idle now reads 5°,
muzzle 1.10 m, support hand 5 cm.

`X` holsters the weapon, which is what gives the project a genuine unarmed idle
instead of leaving the unarmed clips unreachable.

### 4. Weapon orientation was one blanket world-space offset

`alignWeaponToHand` set the weapon's *world* rotation to a fixed pitch-only Euler in
whichever pose it was sampled — no per-weapon data at all. It now takes the weapon's
own bore line (grip origin → that model's muzzle node) and rotates that onto the
*character's* forward axis, which no longer depends on the model's authored yaw, plus
a per-weapon `attach` trim from the catalogue. Because the axis comes from the model
itself, every weapon is corrected for its own geometry.

### 5. The support hand never touched the weapon

The retargeted clips pose the left arm for their own invisible weapon, so the hand
floated 20–46 cm away. A two-bone IK solve (`Character._updateSupportHand`) now welds
the left hand to a point on the active weapon's bore line — under the barrel, at 52 %
of its length for long guns and 24 % for pistols — through recoil, reload and aim.
It is skipped while prone and while holstered, where the clip owns both arms.

### 6. Aiming did not aim

Three separate faults:

* **The TPS camera looked at the character, not down the aim ray.** `pitch` only
  orbited the camera, so moving the mouse up or down could not move the crosshair at
  all, and recoil could not move it either. The camera now looks along the aim ray,
  offset to the shoulder — the crosshair is the shot.
* **Recoil was inverted.** In this camera a *negative* pitch delta looks up; the
  kick was being added, i.e. the weapon was being pushed *down*. It is now subtracted
  and clamped to ±0.26 rad, so automatic fire climbs and recovers instead of walking
  the view off target (measured over a 4 s burst: −0.053 … 0.000 rad, camera yaw
  drift 0.00°, base pitch untouched).
* **The weapon ignored the camera.** `Character._updateAimSolve` rotates the held
  weapon about its grip onto the camera's aim ray while RMB is held, clamped to 18°
  so it reads as the arm taking up the angle rather than a turret. Barrel vs aim ray
  went from **18.1° to 0.2°**.

### 7. Prone idle was a deceleration clip

`Prone_Backwards_Stop` slides the body 13 cm down and then keeps drifting; looped, it
bobbed the hips ~40 mm every 0.37 s. It is now trimmed to its settled tail
(`trimStart: 0.84`); the packed `Prone_Idle` file was tried first but it is authored
standing (hips 1.0 m), as is `Crawl_Backwards_In_Prone`. Measured over 10 s with all
keys released: 0 clip changes, hips 0.13 m, per-frame motion below 1 mm — breathing
only.

### What this round did **not** do

* There is still only one FPS arms asset (the AK-47 viewmodel, CC BY 4.0); the other
  18 weapons reuse its hands in first person.
* No browser or live-input testing was available here. Everything above is measured
  headlessly against the real GLB files by `npm run verify-integration`, which
  simulates the actual controller at 60 Hz. Visual quality, material look and how the
  animation *reads* still need a human in the browser.

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
| **1**–**9** | quick-pick weapon |
| **V** | toggle first / third person |
| **Q** / wheel | cycle all 30 weapons |
| **E** | refill all ammunition |
| **T** | reset targets and hit counter |
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

**Weapons.** 19 downloaded weapon models (see the r6 section and
`public/assets/ASSETS.md`) with per-weapon handling, a rigged first-person
viewmodel that holds every one of them, and the mechanical parts the assets
themselves provide.

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
| `npm run verify-assets` | 73 assertions: every file documented in ASSETS.md exists, all 19 weapon GLBs load and mount, the FPS rig exposes its clips and gun bones with an auto-aligned barrel, 12 real Ogg sound files, the 1024² particle atlas |
| `npm run verify-weapons` | 114 assertions: 19 downloaded meshes are geometrically distinct, the muzzle end is correctly identified on every one, all mount on both rigs, the assets' own parts animate, fire-mode config |
| `npm run verify-controls` | 100 assertions: walkthrough, single-action invariant, belly-crawl heights on **both rigs**, exclusive states, FPS/TPS state preservation and aim alignment, every weapon's fire + reload + mechanics, ammunition accounting, range hitscan in both camera modes, melee reach |
| `npm run verify-integration` | 64 assertions for the r7 fix brief: armed idle held for 10 s, armed walk/run/sprint, aim ray vs barrel, recoil direction and bounds, reload in place for four weapons, prone entry/crawl/get-up, and grip + barrel + aim + fire on all 19 weapons |
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
| `weapons/*.glb` (19 files) | Quaternius "Ultimate Guns", plus 2 Sketchfab CC BY models | see `public/assets/ASSETS.md` | CC0 / CC BY 4.0 |
| `fps/ak47_hands.glb` | [ALAKH-BEST/BulletFall](https://github.com/ALAKH-BEST/BulletFall) | "Ak47" by kursat_sokmen | CC BY 4.0 |
| `sfx/*.opus` (12 files) | [DeathByDenim/openspades-free-pak](https://github.com/DeathByDenim/openspades-free-pak) | Free Firearm Sound Library and others | CC0 |
| `fx/particles.png` | [Karnak19/bagarre](https://github.com/Karnak19/bagarre) | Kenney Particle Pack | CC0 1.0 |
| `animations/*.glb` (49 files) | [MisterYI/deevid-mixamo-assets · anim/](https://github.com/MisterYI/deevid-mixamo-assets) | raw Mixamo motion clips, X-Bot rig | Mixamo asset — royalty-free use |

Notes on licensing and honesty:

* Mixamo content is licensed for use in commercial and non-commercial projects without
  attribution under the [Mixamo terms](https://helpx.adobe.com/creative-cloud/faq/mixamo-faq.html);
  the characters/animation *content* is Adobe's, the *use* here is permitted. Both mirrors
  redistribute it openly, and three.js ships the two character GLBs in its own
  MIT-licensed repository for exactly this purpose.
* The clip names in `src/config.js` (`source:` field) are the original Mixamo names, so each
  `.glb` stays traceable to its upstream file. `fetch-assets.mjs` re-downloads them by name.
* **Weapons, first-person arms, gunfire/impact audio and the particle atlas are all
  downloaded** as of r6; their exact upstream file, author and licence (CC0 for
  Quaternius/Kenney/OpenSpades, CC BY 4.0 for the two Sketchfab weapons and the AK-47
  hands) are tabulated in [`public/assets/ASSETS.md`](public/assets/ASSETS.md). The CC BY
  models **require attribution** and it is given there and below:
  * "870 MCS Shotgun" by FelipeMSX — https://sketchfab.com/3d-models/870-mcs-shotgun-8fa7d482920e4eda8648766fb38596c0 — CC BY 4.0
  * "Low-Poly Browning M2E2" by Kaan — https://skfb.ly/ovSrR — CC BY 4.0
  * "Ak47" first-person rigged model by kursat_sokmen — https://skfb.ly/6UEL9 — CC BY 4.0
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
3. **Weapon-specific reload animation comes from the assets, not from Mixamo.** Mixamo's
   library has no per-weapon reload clips; it has `Reloading`, `Reload` and
   `Prone_Reloading` (one per posture, all three used). The weapon-specific part of a reload
   is the weapon's own animated geometry plus the downloaded `reload_empty` FPS clip.
4. **Some Mixamo clips are mislabelled upstream.** Two files whose names suggest prone poses
   are actually upright; the prone set therefore uses `Prone_Backwards_Stop` as the prone
   idle, `Crawling` / `Crawling_Backwards` for crawl, and `Kip_Up` to get up. Similar
   substitutions: `Crouched_Sneaking_Right` is the only usable crouch-right clip,
   `Pistol_Strafe` stands in for forward pistol aim-walk (no such clip exists), and
   `Gunplay` is used as the looping pistol firing pose.
5. **Root motion is stripped.** Clips keep vertical hip motion but zero XZ drift; translation
   is driven by the controller so speed changes don't fight the animation.
6. **No IK, no foot planting, no ragdoll.** Deaths are clip-driven, not physics.
7. **Single rig pair.** Only the two bundled characters are supported; adding another Mixamo
   GLB means adding a `CHARACTERS` entry (and, if it faces another way, a `modelYaw`).
