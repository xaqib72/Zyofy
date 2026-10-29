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
    if (!domElement) throw new Error('Input: a DOM element is required');
    this.dom = domElement;
    this.keys = new Set();
    this.pressed = new Set();      // edge-triggered, cleared every frame
    this.mouse = { dx: 0, dy: 0, left: false, right: false, leftPressed: false, wheel: 0 };
    this.locked = false;

    this._onKeyDown = (e) => {
      // `e.repeat` is the OS auto-repeat: it must never re-trigger an action.
      if (e.repeat) return;
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
    this._onWheel = (e) => {
      if (!this.locked) return;
      e.preventDefault();
      this.mouse.wheel += Math.sign(e.deltaY);
    };

    window.addEventListener('keydown', this._onKeyDown);
    window.addEventListener('keyup', this._onKeyUp);
    window.addEventListener('mousemove', this._onMouseMove);
    window.addEventListener('mousedown', this._onMouseDown);
    window.addEventListener('mouseup', this._onMouseUp);
    document.addEventListener('pointerlockchange', this._onLockChange);
    this.dom.addEventListener('contextmenu', this._onContext);
    this.dom.addEventListener('wheel', this._onWheel, { passive: false });
    this._onClick = () => {
      if (!this.locked) this.dom.requestPointerLock?.();
    };
    this.dom.addEventListener('click', this._onClick);
    this._disposed = false;
  }

  /** Detaches every listener. Safe to call twice; prevents duplicate bindings. */
  dispose() {
    if (this._disposed) return;
    this._disposed = true;
    window.removeEventListener('keydown', this._onKeyDown);
    window.removeEventListener('keyup', this._onKeyUp);
    window.removeEventListener('mousemove', this._onMouseMove);
    window.removeEventListener('mousedown', this._onMouseDown);
    window.removeEventListener('mouseup', this._onMouseUp);
    document.removeEventListener('pointerlockchange', this._onLockChange);
    this.dom.removeEventListener('contextmenu', this._onContext);
    this.dom.removeEventListener('wheel', this._onWheel);
    this.dom.removeEventListener('click', this._onClick);
    this.keys.clear();
    this.pressed.clear();
  }

  isDown(code) { return this.keys.has(code); }
  wasPressed(code) { return this.pressed.has(code); }

  /** Call once at the end of every frame. */
  endFrame() {
    this.pressed.clear();
    this.mouse.dx = 0;
    this.mouse.dy = 0;
    this.mouse.leftPressed = false;
    this.mouse.wheel = 0;
  }
}
