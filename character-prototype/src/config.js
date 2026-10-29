/**
 * Central configuration for the character test bed.
 *
 * All animation assets live in `public/assets/animations/*.glb`. They are the
 * original Mixamo motion clips (X-Bot rig) downloaded from the public
 * `MisterYI/deevid-mixamo-assets` mirror and renamed here to semantic ids.
 * `source` keeps the original Mixamo clip name so every file stays traceable.
 *
 * `naturalSpeed` is the ground speed (m/s) baked into the clip's root motion,
 * measured offline from the source clip. The controller uses it to time-scale
 * each clip so the feet stay in sync with the actual movement speed
 * (speed / naturalSpeed).
 */
export const CLIP_DEFS = [
  // ---- standing locomotion -------------------------------------------------
  { id: 'idle',          source: 'Idle',                            loop: true,  naturalSpeed: 0.0 },
  { id: 'walk_fwd',      source: 'Walk',                            loop: true,  naturalSpeed: 1.67 },
  { id: 'walk_back',     source: 'Walking_Backward',                loop: true,  naturalSpeed: 1.20 },
  { id: 'walk_left',     source: 'Walk_Left',                       loop: true,  naturalSpeed: 1.94 },
  { id: 'walk_right',    source: 'Standing_Walk_Right',             loop: true,  naturalSpeed: 1.38 },
  { id: 'run_fwd',       source: 'Run_Forward',                     loop: true,  naturalSpeed: 4.82 },
  { id: 'run_back',      source: 'Standing_Run_Back',               loop: true,  naturalSpeed: 3.22 },
  { id: 'run_left',      source: 'Standing_Run_Left',               loop: true,  naturalSpeed: 3.52 },
  { id: 'run_right',     source: 'Standing_Run_Right',              loop: true,  naturalSpeed: 3.96 },
  { id: 'sprint_fwd',    source: 'Sprint',                          loop: true,  naturalSpeed: 6.12 },

  // ---- jump / air ----------------------------------------------------------
  { id: 'jump_takeoff',  source: 'Jump_Up',                         loop: false, naturalSpeed: 0.0 },
  { id: 'jump_air',      source: 'Falling',                         loop: true,  naturalSpeed: 0.0 },
  { id: 'jump_land',     source: 'Fall_B_Land_To_Standing_Idle',    loop: false, naturalSpeed: 0.0 },

  // ---- crouch --------------------------------------------------------------
  { id: 'crouch_idle',   source: 'Crouch_Idle_01',                  loop: true,  naturalSpeed: 0.0 },
  { id: 'crouch_fwd',    source: 'Walk_Crouching_Forward',          loop: true,  naturalSpeed: 2.05 },
  { id: 'crouch_back',   source: 'Crouch_Walk_Back',                loop: true,  naturalSpeed: 1.28 },
  { id: 'crouch_left',   source: 'Crouch_Walk_Left',                loop: true,  naturalSpeed: 1.42 },
  { id: 'crouch_right',  source: 'Crouched_Sneaking_Right',         loop: true,  naturalSpeed: 0.58 },
  { id: 'crouch_stand',  source: 'Crouch_To_Standing_With_Rifle',   loop: false, naturalSpeed: 0.0 },

  // ---- prone ---------------------------------------------------------------
  // `prone_idle` / `crawl_*` are all *belly-level* clips: measured hips stay
  // 0.13-0.26 m and the chest 0.22-0.28 m above the floor, with the knees never
  // leaving the ground (knee max 0.13 m). The old forward crawl used Mixamo's
  // `Crawling`, which holds the hips at 0.44 m — a hands-and-knees baby crawl —
  // so forward movement now uses `Zombie_Crawl`, a genuine flat drag where the
  // elbows and forearms pull the body along.
  { id: 'prone_idle',    source: 'Prone_Backwards_Stop',            loop: true,  naturalSpeed: 0.0 },
  { id: 'crawl_fwd',     source: 'Zombie_Crawl',                    loop: true,  naturalSpeed: 0.43, hipYOffset: 0.01 },
  { id: 'crawl_back',    source: 'Crawling_Backwards',              loop: true,  naturalSpeed: 1.45, hipYOffset: 0.02 },
  { id: 'crawl_left',    source: 'Prone_Left_Turn',                 loop: true,  naturalSpeed: 0.0, hipYOffset: 0.02 },
  { id: 'crawl_right',   source: 'Prone_Right_Turn',                loop: true,  naturalSpeed: 0.0 },
  { id: 'prone_down',    source: 'Burpee_Start',                    loop: false, naturalSpeed: 0.0 },
  { id: 'prone_stand',   source: 'Kip_Up',                          loop: false, naturalSpeed: 0.0 },

  // ---- aiming (pistol) -----------------------------------------------------
  { id: 'pistol_aim_idle',  source: 'Pistol_Idle',                  loop: true,  naturalSpeed: 0.0 },
  { id: 'pistol_aim_fire',  source: 'Gunplay',                      loop: true,  naturalSpeed: 0.0 },
  { id: 'pistol_aim_left',  source: 'Pistol_Strafe',                loop: true,  naturalSpeed: 2.26 },
  { id: 'pistol_aim_right', source: 'Standing_Aim_Walk_Right',      loop: true,  naturalSpeed: 1.47 },
  { id: 'pistol_aim_back',  source: 'Pistol_Walk_Backward',         loop: true,  naturalSpeed: 1.47 },
  { id: 'crouch_aim_idle',  source: 'Idle_Crouching_Aiming',        loop: true,  naturalSpeed: 0.0 },

  // ---- aiming (rifle) ------------------------------------------------------
  { id: 'rifle_aim_idle',   source: 'Rifle_Aiming_Idle',            loop: true,  naturalSpeed: 0.0 },
  { id: 'rifle_aim_walk',   source: 'Rifle_Walk',                   loop: true,  naturalSpeed: 1.46 },
  { id: 'rifle_aim_run',    source: 'Rifle_Run',                    loop: true,  naturalSpeed: 3.15 },
  { id: 'rifle_fire',       source: 'Firing_Rifle',                 loop: true,  naturalSpeed: 0.0 },

  // ---- weapon handling -----------------------------------------------------
  { id: 'reload_stand',     source: 'Reloading',                    loop: false, naturalSpeed: 0.0 },
  { id: 'reload_crouch',    source: 'Reload',                       loop: false, naturalSpeed: 0.0 },
  { id: 'reload_prone',     source: 'Prone_Reloading',              loop: false, naturalSpeed: 0.0 },

  // ---- weapons: draw / fire / melee ---------------------------------------
  // These are one-shot action clips; the controller never loops them.
  { id: 'draw_long',    source: 'Grab_Rifle_From_Back',             loop: false, naturalSpeed: 0.0 },
  { id: 'draw_short',   source: 'Rifle_Aim_To_Down',                loop: false, naturalSpeed: 0.0 },
  { id: 'holster_long', source: 'Rifle_Put_Away',                   loop: false, naturalSpeed: 0.0 },
  { id: 'melee_stab',   source: 'Stabbing',                         loop: false, naturalSpeed: 0.0 },
  { id: 'melee_slash',  source: 'Bayonet_Stab',                     loop: false, naturalSpeed: 0.0 },
  { id: 'knife_idle',   source: 'Rifle_Idle',                       loop: true,  naturalSpeed: 0.0 },

  // ---- damage / death ------------------------------------------------------
  { id: 'hit_light',     source: 'Hit_To_Body',                     loop: false, naturalSpeed: 0.0 },
  { id: 'hit_heavy',     source: 'Standing_React_Large_From_Front', loop: false, naturalSpeed: 0.0 },
  { id: 'death_front',   source: 'Death_From_The_Front',            loop: false, naturalSpeed: 0.0 },
  { id: 'death_back',    source: 'Standing_React_Death_Backward',   loop: false, naturalSpeed: 0.0 },
];

/** Characters. Both are Mixamo-rigged (`mixamorig:*` bones) so clips retarget 1:1 by name. */
export const CHARACTERS = [
  {
    id: 'male',
    label: 'Vanguard — male',
    file: 'assets/characters/vanguard_male.glb',
    note: 'Mixamo "Vanguard By T. Choonyung" — tactical armour, 49-bone rig',
    heightOffset: 0.0,
    // the male rig's belly rides ~2 cm under the floor in the prone clips
    proneLift: 0.03,
    // this GLB is authored facing -Z, so it is wrapped in a 180 deg yaw
    modelYaw: Math.PI,
  },
  {
    id: 'female',
    label: 'Michelle — female',
    file: 'assets/characters/michelle_female.glb',
    note: 'Mixamo "Michelle" — civilian tactical outfit, 65-bone rig (incl. fingers)',
    heightOffset: 0.0,
    // the female rig has a shorter torso, so its prone pose sits deeper
    proneLift: 0.07,
    modelYaw: 0,
  },
];

export const TUNING = {
  walkSpeed: 1.7,
  runSpeed: 4.6,
  sprintSpeed: 6.3,
  crouchSpeed: 1.25,
  crawlSpeed: 0.55,
  aimWalkSpeed: 1.5,
  aimRunSpeed: 3.2,
  aimTurnSpeed: 14,        // rad/s, how fast the body snaps to the camera while aiming
  freeTurnSpeed: 11,       // rad/s, how fast the body snaps to the movement direction
  acceleration: 14,        // 1/s
  gravity: -16.0,
  jumpImpulse: 5.2,
  animationFps: 30,        // sampling rate used when retargeting clips
  crossFade: 0.22,         // seconds
  fastCrossFade: 0.12,
  airSpeed: 3.2,           // max horizontal speed while airborne
};

/**
 * Weapon definitions.
 *
 * `mode` drives the trigger logic: 'auto' reads the held button, 'semi' and
 * 'burst' consume the press edge only, so a semi-automatic weapon can never
 * fire on consecutive frames.
 */
export const WEAPONS = {
  rifle: {
    label: 'Rifle', category: 'ar', magSize: 30, reserve: 120,
    mode: 'auto', fireInterval: 0.1, damage: 28, range: 120,
    spread: 0.005, recoilPitch: 0.024, recoilYaw: 0.006,
    reloadTime: 2.1, firePoseTime: 0.22, damageAnim: 'rifle_fire',
  },
  pistol: {
    label: 'Pistol', category: 'pistol', magSize: 15, reserve: 60,
    mode: 'semi', fireInterval: 0.16, damage: 22, range: 60,
    spread: 0.007, recoilPitch: 0.02, recoilYaw: 0.004,
    reloadTime: 1.7, firePoseTime: 0.18, damageAnim: 'pistol_aim_fire',
  },
};

export const BONE_NAMES = {
  hips: 'mixamorig:Hips',
  spine2: 'mixamorig:Spine2',
  head: 'mixamorig:Head',
  rightHand: 'mixamorig:RightHand',
  leftHand: 'mixamorig:LeftHand',
};
