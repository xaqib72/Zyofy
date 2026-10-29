/**
 * Weapon catalogue.
 *
 * Every entry is a *distinct* procedurally-built model (see weapons.js) plus its
 * own handling numbers. The model parameters below are what make the silhouettes
 * different: barrel length and gauge, receiver size, stock type, magazine type,
 * optic, suppressor, pump/bolt hardware, bipod. Nothing here is one mesh with a
 * changed label - the geometry each set of parameters produces is genuinely
 * different (a 5-round bolt gun with a 26" barrel and a scope is not an MP5 with
 * the name changed).
 *
 * Fields
 *   model      parameters handed to the procedural builder
 *   mode       'semi' | 'auto' | 'burst' | 'bolt' | 'pump' | 'melee'
 *   rpm        rounds per minute (fire interval is derived from it)
 *   magSize / reserve
 *   reloadTime seconds (drives the clip time scale AND the mechanical part anim)
 *   reloadStyle 'mag' | 'cylinder' | 'bolt' | 'pump' | 'none'
 *   cycle      seconds the action takes to cycle after firing (bolt/pump)
 *   pellets    projectiles per shot (shotguns)
 *   spread     radians of cone at rest
 *   recoil     { pitch, yaw, kick } - camera/pose response per shot
 *   firePose   'long' | 'short' - which firing animation family is used
 *   sound      synth profile for the procedural audio (see audio.js)
 */

const BASE = {
  pellets: 1,
  burst: 0,
  cycle: 0,
  equipped: true,
};

export const WEAPON_LIST = [
  /* ------------------------------------------------------------------ pistols */
  {
    ...BASE, id: 'g17', label: 'Glock 17', category: 'pistol', mode: 'semi', rpm: 420,
    damage: 22, range: 70, spread: 0.009, recoil: { pitch: 0.016, yaw: 0.004, kick: 0.03 },
    magSize: 17, reserve: 102, reloadTime: 1.7, reloadStyle: 'mag', firePose: 'short', sound: 'pistol',
    model: { kind: 'pistol', length: 0.2, barrel: 0.11, colour: 0x24262a, slide: true, grips: 'polymer' },
  },
  {
    ...BASE, id: 'm9', label: 'M9 Beretta', category: 'pistol', mode: 'semi', rpm: 400,
    damage: 24, range: 70, spread: 0.008, recoil: { pitch: 0.018, yaw: 0.005, kick: 0.03 },
    magSize: 15, reserve: 90, reloadTime: 1.8, reloadStyle: 'mag', firePose: 'short', sound: 'pistol',
    model: { kind: 'pistol', length: 0.22, barrel: 0.12, colour: 0x2b2d33, slide: true, exposed: true },
  },
  {
    ...BASE, id: 'deagle', label: 'Desert Eagle .50', category: 'pistol', mode: 'semi', rpm: 260,
    damage: 58, range: 85, spread: 0.012, recoil: { pitch: 0.05, yaw: 0.012, kick: 0.09 },
    magSize: 7, reserve: 35, reloadTime: 2.2, reloadStyle: 'mag', firePose: 'short', sound: 'heavy',
    model: { kind: 'pistol', length: 0.27, barrel: 0.17, colour: 0x585c62, slide: true, wide: true },
  },
  {
    ...BASE, id: 'usp_s', label: 'USP-S (suppressed)', category: 'pistol', mode: 'semi', rpm: 380,
    damage: 20, range: 55, spread: 0.006, recoil: { pitch: 0.011, yaw: 0.003, kick: 0.02 },
    magSize: 12, reserve: 72, reloadTime: 2.0, reloadStyle: 'mag', firePose: 'short',
    sound: 'suppressed',
    model: { kind: 'pistol', length: 0.34, barrel: 0.1, colour: 0x1d1f22, slide: true, suppressor: true },
  },

  /* ---------------------------------------------------------------- revolvers */
  {
    ...BASE, id: 'python', label: 'Colt Python .357', category: 'revolver', mode: 'semi', rpm: 180,
    damage: 52, range: 80, spread: 0.008, recoil: { pitch: 0.045, yaw: 0.01, kick: 0.08 },
    magSize: 6, reserve: 30, reloadTime: 3.1, reloadStyle: 'cylinder', firePose: 'short',
    sound: 'heavy',
    model: { kind: 'revolver', length: 0.3, barrel: 0.2, colour: 0x8d9298, vents: true },
  },
  {
    ...BASE, id: 'sw629', label: 'S&W 629 .44', category: 'revolver', mode: 'semi', rpm: 160,
    damage: 62, range: 75, spread: 0.01, recoil: { pitch: 0.055, yaw: 0.014, kick: 0.1 },
    magSize: 6, reserve: 30, reloadTime: 3.4, reloadStyle: 'cylinder', firePose: 'short',
    sound: 'heavy',
    model: { kind: 'revolver', length: 0.26, barrel: 0.14, colour: 0x3a3d42, stubby: true },
  },

  /* --------------------------------------------------------------------- SMGs */
  {
    ...BASE, id: 'mp5', label: 'MP5', category: 'smg', mode: 'auto', rpm: 800,
    damage: 18, range: 90, spread: 0.012, recoil: { pitch: 0.012, yaw: 0.006, kick: 0.02 },
    magSize: 30, reserve: 150, reloadTime: 2.3, reloadStyle: 'mag', firePose: 'long', sound: 'smg',
    model: { kind: 'smg', length: 0.66, barrel: 0.16, colour: 0x22242a, stock: 'retract', mag: 'curved' },
  },
  {
    ...BASE, id: 'mp7', label: 'MP7', category: 'smg', mode: 'auto', rpm: 950,
    damage: 15, range: 80, spread: 0.014, recoil: { pitch: 0.013, yaw: 0.007, kick: 0.02 },
    magSize: 40, reserve: 160, reloadTime: 2.1, reloadStyle: 'mag', firePose: 'long', sound: 'smg',
    model: { kind: 'smg', length: 0.5, barrel: 0.12, colour: 0x2a2c31, stock: 'folding', mag: 'box', foregrip: true },
  },
  {
    ...BASE, id: 'vector', label: 'Vector .45', category: 'smg', mode: 'burst', burst: 2, rpm: 1100,
    damage: 20, range: 75, spread: 0.011, recoil: { pitch: 0.014, yaw: 0.004, kick: 0.02 },
    magSize: 25, reserve: 125, reloadTime: 2.4, reloadStyle: 'mag', firePose: 'long', sound: 'smg',
    model: { kind: 'smg', length: 0.56, barrel: 0.1, colour: 0x33363c, stock: 'folding', mag: 'box', angled: true },
  },
  {
    ...BASE, id: 'uzi', label: 'Uzi', category: 'smg', mode: 'auto', rpm: 600,
    damage: 17, range: 60, spread: 0.02, recoil: { pitch: 0.016, yaw: 0.01, kick: 0.02 },
    magSize: 25, reserve: 125, reloadTime: 2.0, reloadStyle: 'mag', firePose: 'long', sound: 'smg',
    model: { kind: 'smg', length: 0.46, barrel: 0.1, colour: 0x1b1d20, stock: 'wire', mag: 'box', grip: 'mag' },
  },
  {
    ...BASE, id: 'mp5sd', label: 'MP5SD (suppressed)', category: 'smg', mode: 'auto', rpm: 780,
    damage: 16, range: 70, spread: 0.01, recoil: { pitch: 0.01, yaw: 0.005, kick: 0.015 },
    magSize: 30, reserve: 150, reloadTime: 2.3, reloadStyle: 'mag', firePose: 'long',
    sound: 'suppressed',
    model: { kind: 'smg', length: 0.8, barrel: 0.16, colour: 0x23252a, stock: 'retract', mag: 'curved', suppressor: true },
  },

  /* ------------------------------------------------------------ assault rifles */
  {
    ...BASE, id: 'ak47', label: 'AK-47', category: 'ar', mode: 'auto', rpm: 600,
    damage: 36, range: 130, spread: 0.016, recoil: { pitch: 0.034, yaw: 0.016, kick: 0.06 },
    magSize: 30, reserve: 150, reloadTime: 2.5, reloadStyle: 'mag', firePose: 'long', sound: 'rifle',
    model: { kind: 'rifle', length: 0.9, barrel: 0.42, colour: 0x6b4a2f, stock: 'fixed', mag: 'curved', wood: true, gasBlock: true },
  },
  {
    ...BASE, id: 'm4a1', label: 'M4A1', category: 'ar', mode: 'auto', rpm: 800,
    damage: 28, range: 140, spread: 0.01, recoil: { pitch: 0.022, yaw: 0.008, kick: 0.04 },
    magSize: 30, reserve: 150, reloadTime: 2.2, reloadStyle: 'mag', firePose: 'long', sound: 'rifle',
    model: { kind: 'rifle', length: 0.84, barrel: 0.36, colour: 0x2a2c30, stock: 'retract', mag: 'box', rail: true, carry: true },
  },
  {
    ...BASE, id: 'scarl', label: 'SCAR-L', category: 'ar', mode: 'auto', rpm: 650,
    damage: 31, range: 145, spread: 0.011, recoil: { pitch: 0.026, yaw: 0.01, kick: 0.04 },
    magSize: 30, reserve: 150, reloadTime: 2.4, reloadStyle: 'mag', firePose: 'long', sound: 'rifle',
    model: { kind: 'rifle', length: 0.92, barrel: 0.4, colour: 0x6e6a52, stock: 'folding', mag: 'box', tan: true, optic: 'reddot' },
  },
  {
    ...BASE, id: 'ak74u', label: 'AK-74U', category: 'ar', mode: 'auto', rpm: 700,
    damage: 26, range: 95, spread: 0.018, recoil: { pitch: 0.03, yaw: 0.014, kick: 0.05 },
    magSize: 30, reserve: 150, reloadTime: 2.3, reloadStyle: 'mag', firePose: 'long', sound: 'rifle',
    model: { kind: 'rifle', length: 0.62, barrel: 0.18, colour: 0x2e2a26, stock: 'folding', mag: 'curved', short: true },
  },

  /* ------------------------------------------------------------- battle rifles */
  {
    ...BASE, id: 'fal', label: 'FN FAL', category: 'battle', mode: 'auto', rpm: 700,
    damage: 42, range: 170, spread: 0.014, recoil: { pitch: 0.04, yaw: 0.018, kick: 0.07 },
    magSize: 20, reserve: 100, reloadTime: 2.7, reloadStyle: 'mag', firePose: 'long', sound: 'battle',
    model: { kind: 'rifle', length: 1.0, barrel: 0.5, colour: 0x3b3d40, stock: 'fixed', mag: 'box', carry: true },
  },
  {
    ...BASE, id: 'scarh', label: 'SCAR-H', category: 'battle', mode: 'auto', rpm: 600,
    damage: 46, range: 175, spread: 0.013, recoil: { pitch: 0.044, yaw: 0.02, kick: 0.08 },
    magSize: 20, reserve: 100, reloadTime: 2.6, reloadStyle: 'mag', firePose: 'long', sound: 'battle',
    model: { kind: 'rifle', length: 0.98, barrel: 0.46, colour: 0x6e6a52, stock: 'folding', mag: 'box', tan: true, optic: 'scope' },
  },

  /* ----------------------------------------------------------- marksman rifles */
  {
    ...BASE, id: 'm14', label: 'M14 EBR', category: 'marksman', mode: 'semi', rpm: 380,
    damage: 55, range: 220, spread: 0.006, recoil: { pitch: 0.045, yaw: 0.012, kick: 0.08 },
    magSize: 20, reserve: 80, reloadTime: 2.9, reloadStyle: 'mag', firePose: 'long', sound: 'battle',
    model: { kind: 'rifle', length: 1.05, barrel: 0.52, colour: 0x23252a, stock: 'fixed', mag: 'box', optic: 'scope', rail: true },
  },
  {
    ...BASE, id: 'svd', label: 'SVD Dragunov', category: 'marksman', mode: 'semi', rpm: 340,
    damage: 62, range: 250, spread: 0.005, recoil: { pitch: 0.05, yaw: 0.014, kick: 0.09 },
    magSize: 10, reserve: 60, reloadTime: 3.0, reloadStyle: 'mag', firePose: 'long', sound: 'battle',
    model: { kind: 'rifle', length: 1.1, barrel: 0.56, colour: 0x5a4326, stock: 'fixed', mag: 'box', optic: 'scope', wood: true },
  },

  /* ------------------------------------------------------------ sniper rifles */
  {
    ...BASE, id: 'awp', label: 'AWP', category: 'sniper', mode: 'bolt', rpm: 45, cycle: 1.15,
    damage: 130, range: 400, spread: 0.002, recoil: { pitch: 0.075, yaw: 0.01, kick: 0.14 },
    magSize: 5, reserve: 30, reloadTime: 3.6, reloadStyle: 'bolt', firePose: 'long', sound: 'sniper',
    model: { kind: 'rifle', length: 1.2, barrel: 0.62, colour: 0x2f3a33, stock: 'fixed', mag: 'box', optic: 'scope', bolt: true, bipod: false },
  },
  {
    ...BASE, id: 'm24', label: 'M24', category: 'sniper', mode: 'bolt', rpm: 50, cycle: 1.0,
    damage: 115, range: 380, spread: 0.0025, recoil: { pitch: 0.07, yaw: 0.009, kick: 0.13 },
    magSize: 5, reserve: 30, reloadTime: 3.4, reloadStyle: 'bolt', firePose: 'long', sound: 'sniper',
    model: { kind: 'rifle', length: 1.12, barrel: 0.58, colour: 0x3a3d42, stock: 'fixed', mag: 'box', optic: 'scope', bolt: true },
  },
  {
    ...BASE, id: 'sr25', label: 'SR-25 (semi-auto)', category: 'sniper', mode: 'semi', rpm: 220,
    damage: 85, range: 300, spread: 0.004, recoil: { pitch: 0.055, yaw: 0.014, kick: 0.1 },
    magSize: 20, reserve: 100, reloadTime: 2.9, reloadStyle: 'mag', firePose: 'long', sound: 'sniper',
    model: { kind: 'rifle', length: 1.06, barrel: 0.5, colour: 0x2a2c30, stock: 'retract', mag: 'box', optic: 'scope', rail: true },
  },

  /* ---------------------------------------------------------------- shotguns */
  {
    ...BASE, id: 'r870', label: 'Remington 870 (pump)', category: 'shotgun', mode: 'pump', rpm: 75, cycle: 0.62,
    damage: 13, pellets: 9, range: 26, spread: 0.055, recoil: { pitch: 0.06, yaw: 0.012, kick: 0.11 },
    magSize: 8, reserve: 40, reloadTime: 3.8, reloadStyle: 'pump', firePose: 'long', sound: 'shotgun',
    model: { kind: 'shotgun', length: 1.0, barrel: 0.5, colour: 0x4a3524, stock: 'fixed', mag: 'tube', pump: true, wood: true },
  },
  {
    ...BASE, id: 'spas12', label: 'SPAS-12 (pump)', category: 'shotgun', mode: 'pump', rpm: 70, cycle: 0.7,
    damage: 14, pellets: 9, range: 24, spread: 0.06, recoil: { pitch: 0.065, yaw: 0.014, kick: 0.12 },
    magSize: 8, reserve: 40, reloadTime: 4.0, reloadStyle: 'pump', firePose: 'long', sound: 'shotgun',
    model: { kind: 'shotgun', length: 1.02, barrel: 0.46, colour: 0x1f2124, stock: 'folding', mag: 'tube', pump: true, heatShield: true },
  },
  {
    ...BASE, id: 'm4semi', label: 'Benelli M4 (semi)', category: 'shotgun', mode: 'semi', rpm: 160,
    damage: 11, pellets: 9, range: 28, spread: 0.052, recoil: { pitch: 0.048, yaw: 0.01, kick: 0.09 },
    magSize: 6, reserve: 36, reloadTime: 3.2, reloadStyle: 'mag', firePose: 'long', sound: 'shotgun',
    model: { kind: 'shotgun', length: 0.95, barrel: 0.44, colour: 0x23252a, stock: 'retract', mag: 'tube', pump: false },
  },

  /* ------------------------------------------------------------- machine guns */
  {
    ...BASE, id: 'm249', label: 'M249 SAW', category: 'lmg', mode: 'auto', rpm: 800,
    damage: 32, range: 160, spread: 0.02, recoil: { pitch: 0.026, yaw: 0.014, kick: 0.05 },
    magSize: 100, reserve: 400, reloadTime: 4.6, reloadStyle: 'mag', firePose: 'long', sound: 'lmg',
    model: { kind: 'lmg', length: 1.05, barrel: 0.5, colour: 0x2c2f33, stock: 'fixed', mag: 'drum', bipod: true, carry: true },
  },
  {
    ...BASE, id: 'pkm', label: 'PKM', category: 'lmg', mode: 'auto', rpm: 650,
    damage: 44, range: 190, spread: 0.022, recoil: { pitch: 0.034, yaw: 0.02, kick: 0.07 },
    magSize: 100, reserve: 400, reloadTime: 5.0, reloadStyle: 'mag', firePose: 'long', sound: 'lmg',
    model: { kind: 'lmg', length: 1.12, barrel: 0.56, colour: 0x4a3a28, stock: 'fixed', mag: 'belt', bipod: true, wood: true },
  },

  /* -------------------------------------------------------------------- melee */
  {
    ...BASE, id: 'combat_knife', label: 'Combat Knife', category: 'melee', mode: 'melee', rpm: 130,
    damage: 55, range: 2.2, spread: 0, recoil: { pitch: 0.01, yaw: 0.006, kick: 0.02 },
    magSize: 0, reserve: 0, reloadTime: 0, reloadStyle: 'none', firePose: 'melee', sound: 'knife',
    model: { kind: 'knife', length: 0.3, blade: 0.18, colour: 0x9aa0a6, guard: true },
  },
  {
    ...BASE, id: 'tactical_knife', label: 'Tactical Knife', category: 'melee', mode: 'melee', rpm: 180,
    damage: 42, range: 1.9, spread: 0, recoil: { pitch: 0.008, yaw: 0.004, kick: 0.02 },
    magSize: 0, reserve: 0, reloadTime: 0, reloadStyle: 'none', firePose: 'melee', sound: 'knife',
    model: { kind: 'knife', length: 0.26, blade: 0.14, colour: 0x2b2d31, serrated: true },
  },
  {
    ...BASE, id: 'machete', label: 'Machete', category: 'melee', mode: 'melee', rpm: 95,
    damage: 80, range: 2.6, spread: 0, recoil: { pitch: 0.014, yaw: 0.01, kick: 0.03 },
    magSize: 0, reserve: 0, reloadTime: 0, reloadStyle: 'none', firePose: 'melee', sound: 'knife',
    model: { kind: 'knife', length: 0.52, blade: 0.42, colour: 0x6d7076, machete: true },
  },
];

/** id -> definition */
export const WEAPONS = Object.fromEntries(WEAPON_LIST.map((w) => [w.id, w]));

/** Order used by the weapon wheel / quick switch. */
export const WEAPON_ORDER = WEAPON_LIST.map((w) => w.id);

export const DEFAULT_WEAPON = 'm4a1';
