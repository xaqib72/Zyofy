/**
 * Keyboard + mouse input with pointer lock.
 *
 * Bindings follow the prototype spec, plus a few debug-only extras that exist
 * purely so every required animation can be triggered and inspected:
 *   WASD      move                Mouse     camera
 *   Shift     sprint              RMB       aim
 *   Space     jump                LMB       shoot
 *   Ctrl      crouch (hold/toggle) R        reload
 *   Z         prone toggle        Tab       switch male/female
 *   H / J     take damage         K         die
 *   G         get up / recover    1 / 2     pistol / rifle
 */
export class Input {
  constructor(domElement) {
    this.dom = domElement;
    this.keys = new Set();
    this.pressed = new Set();      // edge-triggered, cleared every frame
    this.mouse = { dx: 0, dy: 0, left: false, right: false, leftPressed: false };
    this.locked = false;

    this._onKeyDown = (e) => {
      const code = e.code;
      if (!this.keys.has(code)) this.pressed.add(code);
      this.keys.add(code);
      // stop the browser from scrolling / opening quick-find etc.
      if (['Space', 'Tab', 'ControlLeft', 'ControlRight', 'ArrowUp', 'ArrowDown'].includes(code)) e.preventDefault();
    };
    this._onKeyUp = (e) => this.keys.delete(e.code);
    this._onMouseMove = (e) => {
      if (!this.locked) return;
      this.mouse.dx += e.movementX;
      this.mouse.dy += e.movementY;
    };
    this._onMouseDown = (e) => {
      if (!this.locked) return;
      if (e.button === 0) { this.mouse.left = true; this.mouse.leftPressed = true; }
      if (e.button === 2) this.mouse.right = true;
    };
    this._onMouseUp = (e) => {
      if (e.button === 0) this.mouse.left = false;
      if (e.button === 2) this.mouse.right = false;
    };
    this._onLockChange = () => {
      this.locked = document.pointerLockElement === this.dom;
      if (!this.locked) { this.mouse.left = false; this.mouse.right = false; }
    };
    this._onContext = (e) => e.preventDefault();

    window.addEventListener('keydown', this._onKeyDown);
    window.addEventListener('keyup', this._onKeyUp);
    window.addEventListener('mousemove', this._onMouseMove);
    window.addEventListener('mousedown', this._onMouseDown);
    window.addEventListener('mouseup', this._onMouseUp);
    document.addEventListener('pointerlockchange', this._onLockChange);
    this.dom.addEventListener('contextmenu', this._onContext);
    this._onClick = () => {
      if (!this.locked) this.dom.requestPointerLock?.();
    };
    this.dom.addEventListener('click', this._onClick);
  }

  isDown(code) { return this.keys.has(code); }
  wasPressed(code) { return this.pressed.has(code); }

  /** Call once at the end of every frame. */
  endFrame() {
    this.pressed.clear();
    this.mouse.dx = 0;
    this.mouse.dy = 0;
    this.mouse.leftPressed = false;
  }
}
