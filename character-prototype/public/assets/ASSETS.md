# Downloaded assets — sources, licences, what was actually integrated

Everything below was downloaded into this repository and is loaded by the running
app. Nothing in this table is planned, approximated or synthesised.

## Weapon models — `public/assets/weapons/`

| file | weapon | original asset | pack / author | licence | source |
| --- | --- | --- | --- | --- | --- |
| `glock.glb` | Glock 17 | `Pistol_5` | Ultimate Guns — Quaternius | CC0 1.0 | https://quaternius.com/packs/ultimategun.html (mirrored in `juninmd/a-plaga`, `public/models/weapons/`) |
| `usp.glb` | M9 Beretta | `Pistol_1` | Ultimate Guns — Quaternius | CC0 1.0 | as above |
| `deagle.glb` | Desert Eagle .50 | `Pistol_3` | Ultimate Guns — Quaternius | CC0 1.0 | as above |
| `burst-pistol.glb` | Burst Pistol | `Pistol_6` | Ultimate Guns — Quaternius | CC0 1.0 | https://github.com/Karnak19/bagarre `apps/client/public/models/guns/` |
| `revolver.glb` | Colt Python .357 | `Revolver_1` | Ultimate Guns — Quaternius | CC0 1.0 | as above |
| `mp5.glb` | MP5 | `SubmachineGun_4` | Ultimate Guns — Quaternius | CC0 1.0 | `juninmd/a-plaga` |
| `smg.glb` | SMG-1 Compact | `SubmachineGun_1` | Ultimate Guns — Quaternius | CC0 1.0 | `Karnak19/bagarre` |
| `ak47.glb` | AK-47 | `AssaultRifle_2` | Ultimate Guns — Quaternius | CC0 1.0 | `juninmd/a-plaga` |
| `m4a1.glb` | M4A1 | `AssaultRifle2_1` | Ultimate Guns — Quaternius | CC0 1.0 | `juninmd/a-plaga` |
| `quaternius_r91.glb` | Type 91 | `AssaultRifle_1` | Ultimate Guns — Quaternius | CC0 1.0 | https://github.com/DaSarBe/army_diary `island/assets/quaternius/rifle_t91.glb` |
| `m249.glb` | Bullpup Carbine | `Bullpup_2` | Ultimate Guns — Quaternius | CC0 1.0 | `juninmd/a-plaga` |
| `sniper.glb` | Marksman Rifle | `SniperRifle_1` | Ultimate Guns — Quaternius | CC0 1.0 | `Karnak19/bagarre` |
| `awp.glb` | AWP | `SniperRifle_3` | Ultimate Guns — Quaternius | CC0 1.0 | `juninmd/a-plaga` |
| `m3.glb` | M3 Field | `Shotgun_3` | Ultimate Guns — Quaternius | CC0 1.0 | `juninmd/a-plaga` |
| `xm1014.glb` | XM1014 | `Shotgun_1` | Ultimate Guns — Quaternius | CC0 1.0 | `juninmd/a-plaga` |
| `shotgun.glb` | Sawed-off | `Shotgun_ShortStock` | Ultimate Guns — Quaternius | CC0 1.0 | `Karnak19/bagarre` |
| `knife.glb` | Combat Knife | `Bayonet_2` | Ultimate Guns — Quaternius | CC0 1.0 | `juninmd/a-plaga` |
| `shotgun_real.glb` | Remington 870 MCS | “870 MCS Shotgun” | FelipeMSX (Sketchfab) | **CC BY 4.0** | https://sketchfab.com/3d-models/870-mcs-shotgun-8fa7d482920e4eda8648766fb38596c0, via `ALAKH-BEST/BulletFall` |
| `lmg_real.glb` | Browning M2 | “Low-Poly Browning M2E2” | Kaan (Sketchfab) | **CC BY 4.0** | https://skfb.ly/ovSrR, via `ALAKH-BEST/BulletFall` |

Quaternius publishes the pack under CC0 1.0
(https://quaternius.com/license.html — free for personal and commercial use, no
attribution required). The two Sketchfab models are CC BY 4.0 and **require
attribution**, which this file provides.

## First-person arms + weapon — `public/assets/fps/`

| file | contents | original asset | licence | source |
| --- | --- | --- | --- | --- |
| `ak47_hands.glb` | skinned gloved arms holding an AK-47, with `idle`, `fire` and `reload_empty` animation clips; the gun has its own bones (`gun`, `slider`, `magazine`, `trigger`, `gun_end`) | “Ak47” first-person rigged model | **CC BY 4.0** | kursat_sokmen, https://skfb.ly/6UEL9, via `ALAKH-BEST/BulletFall` |

This is the first-person viewmodel for the whole game: for the AK-47 its own gun
mesh is shown; for every other weapon the downloaded weapon model is parented to
the asset's `gun` bone (see `src/fpsRig.js`), so the real animated hands hold
every weapon and the real `fire` / `reload_empty` clips play for all of them.

## Sound effects — `public/assets/sfx/`

All from the OpenSpades **free asset pak** (CC0 drop-in pack), whose own credits
file documents each upstream source. `https://github.com/DeathByDenim/openspades-free-pak`

| file | used for | original source | licence |
| --- | --- | --- | --- |
| `rifle_fire.opus` | rifle / DMR / LMG shot | Free Firearm Sound Library (`A_42P`) — Ben Jaszczak et al., https://opengameart.org/content/the-free-firearm-sound-library | CC0 |
| `smg_fire.opus` | SMG / pistol / revolver shot | same library | CC0 |
| `shotgun_fire.opus` | shotgun shot | same library | CC0 |
| `shotgun_cock.opus` | pump action, melee whoosh | same pak (`Shotgun/Cock`) | CC0 |
| `rifle_reload.opus` | bolt/mag reload | https://opengameart.org/content/handgun-reload-sound-effect — zer0_sol | CC0 |
| `smg_reload.opus` | SMG reload | OpenSpades free pak | CC0 |
| `shotgun_reload.opus` | shotgun reload | OpenSpades free pak | CC0 |
| `dry_fire.opus` | empty magazine | https://opengameart.org/content/100-cc0-metal-and-wood-sfx — rubberduck | CC0 |
| `switch.opus` | weapon change | OpenSpades free pak | CC0 |
| `melee_hit.opus` | knife impact | OpenSpades free pak (`Spade/HitPlayer`) | CC0 |
| `impact_flesh.opus` | bullet hitting a target | https://freesound.org/people/magnuswaker/sounds/522091/ | CC0 |
| `impact_ricochet.opus` | bullet missing | https://freesound.org/people/cedarstudios/sounds/148827/ | CC0 |

## Effects — `public/assets/fx/`

| file | contents | original asset | licence | source |
| --- | --- | --- | --- | --- |
| `particles.png` | 4×4 sprite atlas of muzzle flashes (4 kinds), sparks, tracer streaks, smoke, scorch marks and a glow | Kenney “Particle Pack” | CC0 1.0 | https://kenney.nl/assets/particle-pack, via `Karnak19/bagarre` `apps/client/public/vfx/particles.png` |

Cell indices are the pack's own (`muzzle_01`, `muzzle_02`, `muzzle_04`,
`muzzle_05`, `spark`, `trace_07`, `scorch`); see `PARTICLE_CELL` in
`src/weapons.js`.

## Character animation — `public/assets/animations/`

49 Mixamo clips (X-Bot rig), retargeted onto both characters at load time. Mixamo
content is royalty-free and usable in commercial projects without attribution
(https://helpx.adobe.com/creative-cloud/faq/mixamo-faq.html); the files come from
the public mirror `https://github.com/MisterYI/deevid-mixamo-assets`. The original
Mixamo clip name for every file is recorded in `src/config.js` (`source`).

r7 added five clips from the same pack, because the project was missing the poses
the fix brief asked for — a two-handed weapon carry and a knife pose:

| file | Mixamo clip | why |
| --- | --- | --- |
| `rifle_ready_idle.glb` | `Rifle_Idle` | armed idle: both hands on the weapon |
| `rifle_ready_fwd.glb` | `Walk_With_Rifle` | armed walk |
| `rifle_ready_run.glb` | `Rifle_Run` | armed run |
| `rifle_aim_walk.glb` | `Standing_Aim_Walk_Right` | aim-walk (the pack's `Rifle_Walk` is a transition whose hips rise 0.7 m) |
| `crouch_idle.glb` | `Crouch_Idle` | crouch loop (the pack's `Crouch_Idle_01` is a crouch→stand transition) |
| `knife_idle.glb` | `Idle` | the pack's `Knife_Idle` is a 4.3 m lunge, so it cannot loop as an idle |
| `pistol_aim_fire.glb` | `Pistol_Idle` | the pack's `Gunplay` is a crouch-and-rise, not a shot |

**Measured, not assumed.** This pack is full of mislabelled files — `Prone_Idle`
is authored standing, `Backwards_Rifle_Walk` ends lying on the floor and
`Crouch_Idle_01` stands up. Every clip used here was therefore chosen from
measurements of its own hip trajectory (duration, hip-height range, ground travel
per second) rather than from its filename; `scripts/_scanclips.mjs` produces that
table for the whole 885-clip pack, and `naturalSpeed` values in `src/config.js` are
the measured stride speeds, so the feet do not skate.

## Not obtainable

| wanted | why it could not be included |
| --- | --- |
| **weapon-specific reload animations from Mixamo** | Mixamo's action library has no per-weapon reload set (it ships generic `Reload`, `Reloading`, `Prone_Reloading`). Those three *are* used — one per posture — and the weapon-specific part of a reload is performed by the weapon's own animated parts (870 Action Bar, revolver cylinder, AK bolt) plus the downloaded `reload_empty` clip in first person. |
| **optically correct scope reticles** | no CC0 reticle/sight picture pack was reachable; the sight picture is drawn with the weapon's own real zoom (19° FOV) and CSS, not a downloaded texture — see the note in the README. |
| **first-person arms for other weapons** | a rigged FPS arms asset exists only for the AK (the model used). Buying or scraping others would need an authenticated store account. |
