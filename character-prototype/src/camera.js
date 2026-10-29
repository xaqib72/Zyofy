import * as THREE from 'three';

/**
 * Third-person orbit camera: mouse controls yaw/pitch, RMB pulls the camera
 * into an over-the-shoulder aim framing, recoil adds a short pitch kick.
 */
export class ThirdPersonCamera {
  constructor(camera, { sensitivity = 0.0022 } = {}) {
    this.camera = camera;
    this.yaw = Math.PI;
    this.pitch = 0.22;
    this.sensitivity = sensitivity;
    this.distance = 3.9;
    this.shoulder = 0.15;
    this._smoothDistance = this.distance;
    this._position = new THREE.Vector3(0, 1.8, -4);
    this._target = new THREE.Vector3(0, 1.4, 0);
    this._tmp = new THREE.Vector3();
    this._dir = new THREE.Vector3();
    this._right = new THREE.Vector3();
    this._initialised = false;
  }

  update(dt, input, character, controller) {
    if (input.locked) {
      this.yaw -= input.mouse.dx * this.sensitivity;
      this.pitch += input.mouse.dy * this.sensitivity;
    }
    this.pitch = THREE.MathUtils.clamp(this.pitch, -0.42, 1.15);

    const aiming = controller.aiming;
    const prone = controller.posture === 'prone';
    const recoil = controller.recoilPitch || 0;

    const baseDistance = prone ? 3.1 : aiming ? 2.35 : 3.9;
    const baseShoulder = prone ? 0.35 : aiming ? 0.62 : 0.2;
    this._smoothDistance += (baseDistance - this._smoothDistance) * Math.min(1, dt * 8);
    this.shoulder += (baseShoulder - this.shoulder) * Math.min(1, dt * 8);

    // aim point: the character's head (or chest while prone)
    const head = character.bones.head;
    if (head) head.getWorldPosition(this._tmp);
    else this._tmp.copy(controller.position).add(new THREE.Vector3(0, 1.4, 0));
    if (prone) this._tmp.y = Math.max(0.25, this._tmp.y);
    this._target.lerp(this._tmp, Math.min(1, dt * 14));

    const pitch = this.pitch + recoil * 0.35;
    this._dir.set(Math.sin(this.yaw) * Math.cos(pitch), -Math.sin(pitch), Math.cos(this.yaw) * Math.cos(pitch)).normalize();
    this._right.set(Math.cos(this.yaw), 0, -Math.sin(this.yaw));

    const desired = this._target.clone()
      .addScaledVector(this._dir, -this._smoothDistance)
      .addScaledVector(this._right, this.shoulder);
    desired.y = Math.max(0.35, desired.y);

    if (!this._initialised) {
      this._position.copy(desired);
      this._initialised = true;
    } else {
      this._position.lerp(desired, Math.min(1, dt * 12));
    }

    this.camera.position.copy(this._position);
    const lookAt = this._target.clone().addScaledVector(this._right, this.shoulder * 0.65);
    this.camera.lookAt(lookAt);
    // small camera roll while strafing sells the movement without extra assets
    this.camera.rotateZ(THREE.MathUtils.clamp(controller.velocity.dot(this._right) * 0.006, -0.03, 0.03));
  }
}
