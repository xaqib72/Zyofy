import * as THREE from 'three';

/**
 * Minimal shooting range.
 *
 * Only targets exist in the world besides the white ground plane: bullseye
 * discs and humanoid silhouettes on thin posts, at short / medium / long
 * distances. No scenery, no buildings, no sky.
 *
 * Hit detection is a plain ray/triangle test against the target meshes (the same
 * maths a hitscan weapon uses) - the ray originates wherever the shot came from
 * (camera in first person, muzzle in third person) and travels along the actual
 * aim direction, so what the crosshair covers is what gets hit.
 */

const WHITE = 0xffffff;

function ringTexture() {
  // Concentric bullseye drawn analytically into a canvas-free texture would
  // need a DOM canvas, so the rings are built from geometry instead (below).
  return null;
}
void ringTexture;

/** A flat bullseye disc built from concentric rings, facing -Z. */
function makeBullseye(radius = 0.45) {
  const group = new THREE.Group();
  const rings = [
    [1.0, 0xf2f2f2],
    [0.78, 0x1f1f22],
    [0.56, 0xe8e8e8],
    [0.34, 0xd23b2e],
    [0.14, 0xf5c542],
  ];
  for (const [k, colour] of rings) {
    const geo = new THREE.CircleGeometry(radius * k, 40);
    const mat = new THREE.MeshStandardMaterial({
      color: colour, roughness: 0.85, metalness: 0.0, side: THREE.DoubleSide,
    });
    const mesh = new THREE.Mesh(geo, mat);
    mesh.position.z = 0.001 * (1 - k);
    group.add(mesh);
  }
  // black rim
  const rim = new THREE.Mesh(
    new THREE.RingGeometry(radius * 0.98, radius * 1.06, 40),
    new THREE.MeshStandardMaterial({ color: 0x14151a, side: THREE.DoubleSide }),
  );
  group.add(rim);
  return group;
}

/** Human-shaped practice silhouette (head + shoulders + torso). */
function makeSilhouette() {
  const group = new THREE.Group();
  const mat = new THREE.MeshStandardMaterial({
    color: 0x2b2f36, roughness: 0.8, metalness: 0.05, side: THREE.DoubleSide,
  });
  const shape = new THREE.Shape();
  // half silhouette, mirrored later by using a symmetric outline
  shape.moveTo(-0.22, -0.85);
  shape.lineTo(0.22, -0.85);
  shape.lineTo(0.26, -0.2);
  shape.lineTo(0.34, -0.05);
  shape.lineTo(0.16, 0.12);
  shape.lineTo(0.15, 0.42);
  shape.lineTo(0.09, 0.55);
  shape.lineTo(-0.09, 0.55);
  shape.lineTo(-0.15, 0.42);
  shape.lineTo(-0.16, 0.12);
  shape.lineTo(-0.34, -0.05);
  shape.lineTo(-0.26, -0.2);
  shape.closePath();

  const body = new THREE.Mesh(new THREE.ShapeGeometry(shape, 12), mat);
  group.add(body);

  const head = new THREE.Mesh(new THREE.CircleGeometry(0.115, 20), mat);
  head.position.set(0, 0.7, 0);
  group.add(head);

  const ringMat = new THREE.MeshStandardMaterial({
    color: 0xd23b2e, side: THREE.DoubleSide, roughness: 0.8,
  });
  const centreRing = new THREE.Mesh(new THREE.RingGeometry(0.05, 0.095, 20), ringMat);
  centreRing.position.set(0, 0.28, 0.002);
  group.add(centreRing);
  const headRing = new THREE.Mesh(new THREE.RingGeometry(0.04, 0.08, 20), ringMat);
  headRing.position.set(0, 0.7, 0.002);
  group.add(headRing);

  return group;
}

function makePost(height) {
  const post = new THREE.Mesh(
    new THREE.CylinderGeometry(0.022, 0.028, height, 8),
    new THREE.MeshStandardMaterial({ color: 0x9aa0a6, roughness: 0.6, metalness: 0.5 }),
  );
  post.position.y = height / 2;
  post.castShadow = true;
  return post;
}

export class ShootingRange {
  constructor(scene) {
    this.scene = scene;
    this.group = new THREE.Group();
    this.group.name = 'shooting_range';
    scene.add(this.group);

    /** meshes that can be hit, each with .userData.target pointing at the target */
    this.hitMeshes = [];
    this.targets = [];
    this.totalHits = 0;
    this.totalDamage = 0;
    this.lastHit = null;

    const bullseyes = [
      { dist: 8, radius: 0.45, height: 1.35 },
      { dist: 15, radius: 0.55, height: 1.5 },
      { dist: 25, radius: 0.7, height: 1.65 },
      { dist: 40, radius: 0.9, height: 1.8 },
      { dist: 60, radius: 1.15, height: 2.0 },
      { dist: 90, radius: 1.5, height: 2.2 },
    ];
    const silhouettes = [
      { dist: 12, scale: 1.0 },
      { dist: 30, scale: 1.15 },
      { dist: 55, scale: 1.35 },
    ];

    let index = 0;
    for (const spec of bullseyes) {
      const face = makeBullseye(spec.radius);
      const target = this._register(`Bullseye ${spec.dist} m`, spec.dist, 'bullseye', face);
      face.position.set(-1.2 + (index % 3) * 2.4, spec.height, -spec.dist);
      face.rotation.y = 0;
      this.group.add(face);
      this.group.add(makePost(spec.height - spec.radius));
      const post = this.group.children.at(-1);
      post.position.x = face.position.x;
      post.position.z = -spec.dist;
      this.group.add(this._label(spec.dist, face.position));
      index += 1;
      void target;
    }

    silhouettes.forEach((spec, i) => {
      const face = makeSilhouette();
      face.scale.setScalar(spec.scale);
      const target = this._register(`Silhouette ${spec.dist} m`, spec.dist, 'humanoid', face);
      void target;
      face.position.set(4.2 + i * 0.2 - i * 4.2, 1.9, -spec.dist);
      this.group.add(face);
      const post = makePost(1.9 - 0.85 * spec.scale);
      post.position.set(face.position.x, post.position.y, -spec.dist);
      this.group.add(post);
      this.group.add(this._label(spec.dist, face.position));
    });

    for (const target of this.targets) {
      target.flash = 0;
    }
  }

  _register(label, distance, kind, object3d) {
    const target = { label, distance, kind, hits: 0, damage: 0, root: object3d, flash: 0 };
    object3d.traverse((o) => {
      if (o.isMesh) {
        o.userData.target = target;
        this.hitMeshes.push(o);
        if (!o.userData.baseColour) {
          o.userData.baseColour = o.material.color ? o.material.color.clone() : null;
          o.userData.baseEmissive = o.material.emissive ? o.material.emissive.clone() : null;
        }
      }
    });
    this.targets.push(target);
    return target;
  }

  _label(distance, where) {
    // distance marker: a small ring on the floor, no text (no font assets)
    const ring = new THREE.Mesh(
      new THREE.RingGeometry(0.24, 0.3, 24),
      new THREE.MeshStandardMaterial({ color: 0xb8bcc2, side: THREE.DoubleSide, roughness: 0.9 }),
    );
    ring.rotation.x = -Math.PI / 2;
    ring.position.set(where.x, 0.003, -distance);
    return ring;
  }

  /**
   * Hitscan.
   * @returns {{point:THREE.Vector3, normal:THREE.Vector3, target:object|null, distance:number, damage:number, killed:boolean}|null}
   */
  hitTest(origin, direction, weapon) {
    // Matrices must be current or the ray tests against last frame's pose.
    this.group.updateMatrixWorld(true);
    const raycaster = new THREE.Raycaster(origin.clone(), direction.clone().normalize(), 0.1, weapon.range);
    const hits = raycaster.intersectObjects(this.hitMeshes, false);

    if (hits.length) {
      const hit = hits[0];
      const target = hit.object.userData.target;
      // Falloff: full damage inside 60% of the effective range, then linear.
      const t = THREE.MathUtils.clamp(hit.distance / (weapon.range * 0.6), 0, 1);
      const falloff = 1 - t * 0.55;
      const damage = Math.max(1, Math.round(weapon.damage * falloff));
      target.hits += 1;
      target.damage += damage;
      target.flash = 0.18;
      this.totalHits += 1;
      this.totalDamage += damage;
      this.lastHit = { label: target.label, distance: hit.distance, damage };
      const normal = hit.face
        ? hit.face.normal.clone().applyQuaternion(hit.object.getWorldQuaternion(new THREE.Quaternion()))
        : direction.clone().negate();
      return {
        point: hit.point, normal, target, distance: hit.distance, damage, killed: false,
      };
    }

    // no target: impact the ground if the ray goes down into it
    if (direction.y < -0.0001) {
      const t = -origin.y / direction.y;
      if (t > 0 && t < weapon.range) {
        const point = origin.clone().addScaledVector(direction, t);
        return { point, normal: new THREE.Vector3(0, 1, 0), target: null, distance: t, damage: 0, killed: false };
      }
    }
    return null;
  }

  /**
   * Where the crosshair actually lands: first target hit along the camera ray,
   * or null when the ray only meets empty ground/sky. Used to converge held
   * melee weapons on the aim point instead of sending swings parallel to it.
   */
  probe(origin, direction, maxDistance = 120) {
    this.group.updateMatrixWorld(true);
    const raycaster = new THREE.Raycaster(origin.clone(), direction.clone().normalize(), 0.05, maxDistance);
    const hits = raycaster.intersectObjects(this.hitMeshes, false);
    return hits.length ? hits[0].point.clone() : null;
  }

  update(dt) {
    for (const target of this.targets) {
      if (target.flash <= 0) continue;
      target.flash = Math.max(0, target.flash - dt);
      const k = target.flash / 0.18;
      target.root.traverse((o) => {
        if (!o.isMesh || !o.material || !o.material.emissive) return;
        o.material.emissive.setRGB(k * 0.9, k * 0.25, k * 0.2);
      });
    }
  }

  reset() {
    this.totalHits = 0;
    this.totalDamage = 0;
    this.lastHit = null;
    for (const target of this.targets) {
      target.hits = 0;
      target.damage = 0;
      target.flash = 0;
      target.root.traverse((o) => {
        if (o.isMesh && o.material && o.material.emissive) o.material.emissive.setRGB(0, 0, 0);
      });
    }
  }

  getDebugState() {
    return {
      hits: this.totalHits,
      damage: this.totalDamage,
      last: this.lastHit ? `${this.lastHit.label} @${this.lastHit.distance.toFixed(1)} m` : '-',
      targets: this.targets.map((t) => ({ label: t.label, hits: t.hits })),
    };
  }
}

export { WHITE };
