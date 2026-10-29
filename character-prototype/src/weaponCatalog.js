/**
 * Weapon catalogue - one *downloaded* model per weapon.
 *
 * Every entry points at a GLB in `public/assets/weapons/` that was fetched from
 * a public asset pack (see `public/assets/ASSETS.md` for the full provenance and
 * licence list). No two entries share a mesh: the `glb` column is unique across
 * the whole catalogue, and `verify-assets.mjs` asserts that both the file and the
 * rendered geometry differ between every pair of weapons.
 *
 * Fields
 *   glb / sourceMesh  the file on disk and the mesh name inside it, so a weapon
 *                     can always be traced back to the pack it came from
 *   modelLength       real-world length in metres; the loader scales the mesh to it
 *   mode              'semi' | 'auto' | 'burst' | 'bolt' | 'pump' | 'melee'
 *   rpm               rounds per minute (fire interval is derived from it)
 *   reloadStyle       'mag' | 'cylinder' | 'pump' | 'bolt' | 'none'
 *   cycle             seconds the action takes to cycle after firing (bolt/pump)
 *   scope             true when the weapon should raise the optical sight overlay
 *   firePose          'long' | 'short' | 'melee' - which Mixamo aim/fire family
 */

const BASE = { pellets: 1, burst: 0, equipped: true, scope: false, cycle: 0 };

export const WEAPON_LIST = [
  /* ------------------------------------------------------ pistols & revolver */
  {
    ...BASE, id: 'g17', label: 'Glock 17', category: 'pistol', mode: 'semi', rpm: 420,
    damage: 22, range: 70, spread: 0.009, recoil: { pitch: 0.016, yaw: 0.004, kick: 0.03 },
    magSize: 17, reserve: 102, reloadTime: 1.7, reloadStyle: 'mag', firePose: 'short', sound: 'pistol',
    glb: 'glock.glb', sourceMesh: 'Pistol_5', modelLength: 0.21,
  },
  {
    ...BASE, id: 'm9', label: 'M9 Beretta', category: 'pistol', mode: 'semi', rpm: 400,
    damage: 24, range: 70, spread: 0.008, recoil: { pitch: 0.018, yaw: 0.005, kick: 0.03 },
    magSize: 15, reserve: 90, reloadTime: 1.8, reloadStyle: 'mag', firePose: 'short', sound: 'pistol',
    glb: 'usp.glb', sourceMesh: 'Pistol_1', modelLength: 0.22,
  },
  {
    ...BASE, id: 'deagle', label: 'Desert Eagle .50', category: 'pistol', mode: 'semi', rpm: 260,
    damage: 58, range: 85, spread: 0.012, recoil: { pitch: 0.05, yaw: 0.012, kick: 0.09 },
    magSize: 7, reserve: 35, reloadTime: 2.2, reloadStyle: 'mag', firePose: 'short', sound: 'heavy',
    glb: 'deagle.glb', sourceMesh: 'Pistol_3', modelLength: 0.27,
  },
  {
    ...BASE, id: 'burst_pistol', label: 'Burst Pistol', category: 'pistol', mode: 'burst', rpm: 900, burst: 3,
    damage: 19, range: 60, spread: 0.011, recoil: { pitch: 0.02, yaw: 0.008, kick: 0.035 },
    magSize: 21, reserve: 84, reloadTime: 1.9, reloadStyle: 'mag', firePose: 'short', sound: 'pistol',
    glb: 'burst-pistol.glb', sourceMesh: 'Pistol_6', modelLength: 0.2,
  },
  {
    ...BASE, id: 'python', label: 'Colt Python .357', category: 'revolver', mode: 'semi', rpm: 240,
    damage: 52, range: 90, spread: 0.007, recoil: { pitch: 0.042, yaw: 0.01, kick: 0.07 },
    magSize: 6, reserve: 36, reloadTime: 2.6, reloadStyle: 'cylinder', firePose: 'short', sound: 'revolver',
    glb: 'revolver.glb', sourceMesh: 'Revolver_1', modelLength: 0.28,
    // The revolver's silhouette is a straight frame with a cylinder in the
    // middle, so the automatic end test cannot tell stock from muzzle; the
    // orientation was checked on the rendered weapon sheet.
    flip: false,
  },

  /* ------------------------------------------------------------------- SMGs */
  {
    ...BASE, id: 'mp5', label: 'MP5', category: 'smg', mode: 'auto', rpm: 800,
    damage: 21, range: 95, spread: 0.013, recoil: { pitch: 0.014, yaw: 0.006, kick: 0.028 },
    magSize: 30, reserve: 150, reloadTime: 2.3, reloadStyle: 'mag', firePose: 'long', sound: 'smg',
    glb: 'mp5.glb', sourceMesh: 'SubmachineGun_4', modelLength: 0.49,
  },
  {
    ...BASE, id: 'smg1', label: 'SMG-1 Compact', category: 'smg', mode: 'auto', rpm: 900,
    damage: 18, range: 80, spread: 0.016, recoil: { pitch: 0.012, yaw: 0.007, kick: 0.024 },
    magSize: 32, reserve: 160, reloadTime: 2.1, reloadStyle: 'mag', firePose: 'long', sound: 'smg',
    glb: 'smg.glb', sourceMesh: 'SubmachineGun_1', modelLength: 0.45,
  },

  /* ---------------------------------------------------------- assault rifles */
  {
    ...BASE, id: 'ak47', label: 'AK-47', category: 'rifle', mode: 'auto', rpm: 600,
    damage: 37, range: 150, spread: 0.014, recoil: { pitch: 0.03, yaw: 0.012, kick: 0.055 },
    magSize: 30, reserve: 120, reloadTime: 2.5, reloadStyle: 'mag', firePose: 'long', sound: 'rifle',
    glb: 'ak47.glb', sourceMesh: 'AssaultRifle_2', modelLength: 0.88,
  },
  {
    ...BASE, id: 'm4a1', label: 'M4A1', category: 'rifle', mode: 'auto', rpm: 750,
    damage: 30, range: 155, spread: 0.011, recoil: { pitch: 0.022, yaw: 0.008, kick: 0.045 },
    magSize: 30, reserve: 120, reloadTime: 2.2, reloadStyle: 'mag', firePose: 'long', sound: 'rifle',
    glb: 'm4a1.glb', sourceMesh: 'AssaultRifle2_1', modelLength: 0.84,
  },
  {
    ...BASE, id: 'type91', label: 'Type 91', category: 'rifle', mode: 'auto', rpm: 650,
    damage: 33, range: 145, spread: 0.013, recoil: { pitch: 0.026, yaw: 0.01, kick: 0.05 },
    magSize: 30, reserve: 120, reloadTime: 2.4, reloadStyle: 'mag', firePose: 'long', sound: 'rifle',
    glb: 'quaternius_r91.glb', sourceMesh: 'AssaultRifle_1', modelLength: 0.9,
  },
  {
    ...BASE, id: 'bullpup', label: 'Bullpup Carbine', category: 'rifle', mode: 'auto', rpm: 700,
    damage: 29, range: 135, spread: 0.012, recoil: { pitch: 0.02, yaw: 0.009, kick: 0.042 },
    magSize: 30, reserve: 120, reloadTime: 2.2, reloadStyle: 'mag', firePose: 'long', sound: 'rifle',
    glb: 'm249.glb', sourceMesh: 'Bullpup_2', modelLength: 0.72,
  },

  /* ---------------------------------------------------- marksman & snipers */
  {
    ...BASE, id: 'dmr', label: 'Marksman Rifle', category: 'sniper', mode: 'semi', rpm: 300,
    damage: 62, range: 210, spread: 0.005, recoil: { pitch: 0.038, yaw: 0.01, kick: 0.06 },
    magSize: 10, reserve: 60, reloadTime: 2.8, reloadStyle: 'mag', firePose: 'long', sound: 'dmr',
    scope: true, glb: 'sniper.glb', sourceMesh: 'SniperRifle_1', modelLength: 1.02,
  },
  {
    ...BASE, id: 'awp', label: 'AWP', category: 'sniper', mode: 'bolt', rpm: 45,
    damage: 118, range: 260, spread: 0.002, recoil: { pitch: 0.07, yaw: 0.014, kick: 0.1 },
    magSize: 5, reserve: 30, reloadTime: 3.2, reloadStyle: 'bolt', cycle: 1.1,
    firePose: 'long', sound: 'sniper', scope: true,
    glb: 'awp.glb', sourceMesh: 'SniperRifle_3', modelLength: 1.2,
  },

  /* ---------------------------------------------------------------- shotguns */
  {
    ...BASE, id: 'r870', label: 'Remington 870 MCS', category: 'shotgun', mode: 'pump', rpm: 70,
    damage: 14, pellets: 9, range: 30, spread: 0.05, recoil: { pitch: 0.05, yaw: 0.012, kick: 0.09 },
    magSize: 6, reserve: 36, reloadTime: 3.0, reloadStyle: 'pump', cycle: 0.75,
    firePose: 'long', sound: 'shotgun',
    glb: 'shotgun_real.glb', sourceMesh: 'FelipeMSX 870 MCS', modelLength: 1.0,
  },
  {
    ...BASE, id: 'm3', label: 'M3 Field', category: 'shotgun', mode: 'pump', rpm: 75,
    damage: 13, pellets: 8, range: 28, spread: 0.055, recoil: { pitch: 0.048, yaw: 0.011, kick: 0.085 },
    magSize: 5, reserve: 30, reloadTime: 2.9, reloadStyle: 'pump', cycle: 0.7,
    firePose: 'long', sound: 'shotgun',
    glb: 'm3.glb', sourceMesh: 'Shotgun_3', modelLength: 0.95,
  },
  {
    ...BASE, id: 'xm1014', label: 'XM1014', category: 'shotgun', mode: 'semi', rpm: 240,
    damage: 11, pellets: 9, range: 28, spread: 0.052, recoil: { pitch: 0.048, yaw: 0.01, kick: 0.09 },
    magSize: 6, reserve: 36, reloadTime: 3.2, reloadStyle: 'mag', firePose: 'long', sound: 'shotgun',
    glb: 'xm1014.glb', sourceMesh: 'Shotgun_1', modelLength: 0.92,
  },
  {
    ...BASE, id: 'shorty', label: 'Sawed-off', category: 'shotgun', mode: 'pump', rpm: 90,
    damage: 16, pellets: 7, range: 18, spread: 0.075, recoil: { pitch: 0.06, yaw: 0.016, kick: 0.1 },
    magSize: 4, reserve: 24, reloadTime: 2.4, reloadStyle: 'pump', cycle: 0.6,
    firePose: 'long', sound: 'shotgun',
    glb: 'shotgun.glb', sourceMesh: 'Shotgun_ShortStock', modelLength: 0.62,
  },

  /* ----------------------------------------------------------- machine guns */
  {
    ...BASE, id: 'm2', label: 'Browning M2', category: 'lmg', mode: 'auto', rpm: 450,
    damage: 48, range: 200, spread: 0.018, recoil: { pitch: 0.035, yaw: 0.018, kick: 0.07 },
    magSize: 100, reserve: 400, reloadTime: 5.2, reloadStyle: 'mag', firePose: 'long', sound: 'lmg',
    glb: 'lmg_real.glb', sourceMesh: 'Kaan M2E2', modelLength: 1.45,
  },

  /* ------------------------------------------------------------------- melee */
  {
    ...BASE, id: 'bayonet', label: 'Combat Knife', category: 'melee', mode: 'melee', rpm: 130,
    damage: 55, range: 1.7, spread: 0, recoil: { pitch: 0.01, yaw: 0.006, kick: 0.02 },
    magSize: 0, reserve: 0, reloadTime: 0, reloadStyle: 'none', firePose: 'melee', sound: 'knife',
    glb: 'knife.glb', sourceMesh: 'Bayonet_2', modelLength: 0.34,
  },
];

/** id -> definition */
export const WEAPONS = Object.fromEntries(WEAPON_LIST.map((w) => [w.id, w]));

/** Order used by the weapon wheel / quick switch. */
export const WEAPON_ORDER = WEAPON_LIST.map((w) => w.id);

export const DEFAULT_WEAPON = 'm4a1';

/** Assert at import time that no mesh file is used twice (no renamed clones). */
const files = WEAPON_LIST.map((w) => w.glb);
if (new Set(files).size !== files.length) {
  throw new Error(`duplicate weapon mesh in catalogue: ${files.join(', ')}`);
}
