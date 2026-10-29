import * as THREE from 'three';

/**
 * Camera rig with two modes.
 *
 *   TPS  spring-arm orbit behind the character, over-the-shoulder while aiming,
 *        low and close while prone, ground-clamped so it never goes underground.
 *   FPS  eye-level, camera parked just in front of the head bone so the head
 *        geometry is behind the near plane (no face in view, nothing to clip
 *        through), with the character's arms and weapon visible underneath.
 *
 * V toggles the mode. The rig never touches the controller's state, which is why
 * switching keeps position, weapon, ammunition and posture exactly as they were.
 */
export class CameraRig {
  constructor(camera, { sensitivity = 0.0022 } = {}) {
    this.camera = camera;
    this.mode = 'tps';
    this.yaw = Math.PI;
    this.pitch = 0.16;
    this.sensitivity = sensitivity;
    this.distance = 3.9;
    this.shoulder = 0.2;
    this._smoothDistance = 3.9;
    this._position = new THREE.Vector3(0, 1.8, -4);
    this._target = new THREE.Vector3(0, 1.4, 0);
    this._tmp = new THREE.Vector3();
    this._dir = new THREE.Vector3();
    this._right = new THREE.Vector3();
    this._initialised = false;
    this._fpsBlend = 0;
    this.modeChangedAt = 0;
  }

  toggle() {
    this.mode = this.mode === 'tps' ? 'fps' : 'tps';
    this.camera.near = this.mode === 'fps' ? 0.02 : 0.1;
    this.camera.updateProjectionMatrix();
    this._initialised = false;
    return this.mode;
  }

  /** Origin of the hitscan ray: always the camera, so shots follow the crosshair. */
  getAimOrigin(out = new THREE.Vector3()) {
    this.camera.updateMatrixWorld(true);
    return out.copy(this.camera.position);
  }

  /** Current aim direction in world space. */
  getAimDirection(out = new THREE.Vector3()) {
    this.camera.updateMatrixWorld(true);
    return this.camera.getWorldDirection(out);
  }

  update(dt, input, character, controller) {
    if (input.locked) {
      this.yaw -= input.mouse.dx * this.sensitivity;
      this.pitch += input.mouse.dy * this.sensitivity;
    }
    const aiming = controller.aiming;
    const prone = controller.posture === 'prone';
    const recoil = controller.recoilPitch || 0;

    if (this.mode === 'fps') {
      this._updateFirstPerson(dt, character, aiming, prone, recoil);
    } else {
      this._updateThirdPerson(dt, character, controller, aiming, prone, recoil);
    }
  }

  _updateFirstPerson(dt, character, aiming, prone, recoil) {
    this.pitch = THREE.MathUtils.clamp(this.pitch, -1.45, 1.45);
    this._fpsBlend = Math.min(1, this._fpsBlend + dt * 5);

    const head = character.bones.head;
    const eye = new THREE.Vector3();
    if (head) head.getWorldPosition(eye);
    else eye.copy(character.group.position).add(new THREE.Vector3(0, 1.62, 0));

    // Feet-below-ground guard: while prone the head drops to ~0.3 m, so clamp so
    // the eye camera never goes under the floor.
    eye.y = Math.max(0.22, eye.y + 0.05);

    const pitch = this.pitch + recoil * 0.5;
    this._dir.set(
      Math.sin(this.yaw) * Math.cos(pitch),
      -Math.sin(pitch),
      Math.cos(this.yaw) * Math.cos(pitch),
    ).normalize();

    // FPS framing: sit just ahead of the face (so the head is behind the near
    // plane) and slightly right of the eye line, which is where a shouldered
    // weapon actually lives - that puts the barrel down the middle of the view
    // instead of pointing across it.
    const right = new THREE.Vector3().crossVectors(new THREE.Vector3(0, 1, 0), this._dir).normalize();
    // Push the eye forward along the aim so the head and chest fall behind the
    // near plane while the forearms and weapon stay in view - the classic FPS
    // framing. 0.17 m (just ahead of the face) leaves the hands only 20 cm from
    // the lens and they swallow the screen.
    const pos = eye.clone()
      .addScaledVector(this._dir, 0.42)
      .addScaledVector(right, -0.1)
      .add(new THREE.Vector3(0, 0.02, 0));

    if (!this._initialised) {
      this._position.copy(pos);
      this._initialised = true;
    } else {
      this._position.lerp(pos, Math.min(1, dt * 26));
    }

    this.camera.position.copy(this._position);
    this.camera.lookAt(this._position.clone().add(this._dir));
    // In first person the body should follow the look direction, or the arms
    // would point sideways whenever the character is strafing.
    void aiming;
    void prone;
  }

  _updateThirdPerson(dt, character, controller, aiming, prone, recoil) {
    this.pitch = THREE.MathUtils.clamp(this.pitch, -0.45, 1.15);
    this._fpsBlend = Math.max(0, this._fpsBlend - dt * 5);

    const baseDistance = prone ? 3.1 : aiming ? 2.3 : 3.9;
    const baseShoulder = prone ? 0.35 : aiming ? 0.62 : 0.2;
    this._smoothDistance += (baseDistance - this._smoothDistance) * Math.min(1, dt * 8);
    this.shoulder += (baseShoulder - this.shoulder) * Math.min(1, dt * 8);

    const head = character.bones.head;
    if (head) head.getWorldPosition(this._tmp);
    else this._tmp.copy(controller.position).add(new THREE.Vector3(0, 1.45, 0));
    if (prone) this._tmp.y = Math.max(0.28, this._tmp.y);
    this._target.lerp(this._tmp, Math.min(1, dt * 14));

    const pitch = this.pitch + recoil * 0.35;
    this._dir.set(
      Math.sin(this.yaw) * Math.cos(pitch),
      -Math.sin(pitch),
      Math.cos(this.yaw) * Math.cos(pitch),
    ).normalize();
    this._right.set(Math.cos(this.yaw), 0, -Math.sin(this.yaw));

    const desired = this._target.clone()
      .addScaledVector(this._dir, -this._smoothDistance)
      .addScaledVector(this._right, this.shoulder);

    // Ground "collision": pull the camera in rather than letting it sink under
    // the white plane, and keep a minimum stand-off from the subject.
    if (desired.y < 0.4) {
      const over = 0.4 - desired.y;
      desired.y = 0.4;
      const back = desired.clone().sub(this._target);
      if (back.lengthSq() > 0.01) desired.addScaledVector(back.normalize(), -Math.min(over, 0.6));
    }

    if (!this._initialised) {
      this._position.copy(desired);
      this._initialised = true;
    } else {
      this._position.lerp(desired, Math.min(1, dt * 12));
    }

    this.camera.position.copy(this._position);
    const lookAt = this._target.clone().addScaledVector(this._right, this.shoulder * 0.65);
    this.camera.lookAt(lookAt);
    this.camera.rotateZ(THREE.MathUtils.clamp(controller.velocity.dot(this._right) * 0.006, -0.03, 0.03));
  }
}
