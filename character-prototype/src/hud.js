/**
 * Minimal DOM HUD: loading progress, live state read-out, control legend and
 * the aim crosshair. No menus, no game UI – this exists so the animations and
 * movement states can be verified while testing.
 */
export class Hud {
  constructor(root) {
    this.root = root;
    root.innerHTML = `
      <canvas id="viewport"></canvas>
      <div id="state" class="panel">
        <h1>Character test bed</h1>
        <div class="row"><span>Character</span><span data-field="character">-</span></div>
        <div class="row"><span>Stance</span><span data-field="posture">-</span></div>
        <div class="row"><span>Aiming</span><span data-field="aim">-</span></div>
        <div class="row"><span>Weapon</span><span data-field="weapon">-</span></div>
        <div class="row"><span>Ammo</span><span data-field="ammo">-</span></div>
        <div class="row"><span>Reserve</span><span data-field="reserve">-</span></div>
        <div class="row"><span>Speed</span><span data-field="speed">-</span></div>
        <div class="row"><span>Grounded</span><span data-field="grounded">-</span></div>
        <div class="clip" data-field="clip">clip: -</div>
        <div class="build" data-field="build">build -</div>
      </div>
      <div id="controls" class="panel">
        <h2>Controls</h2>
        <div class="grid">
          <div><kbd>W</kbd><kbd>A</kbd><kbd>S</kbd><kbd>D</kbd> move</div><div><kbd>Mouse</kbd> look / aim</div>
          <div><kbd>Shift</kbd> sprint</div><div><kbd>RMB</kbd> aim</div>
          <div><kbd>Alt</kbd> walk</div><div><kbd>LMB</kbd> shoot</div>
          <div><kbd>Space</kbd> jump</div><div><kbd>R</kbd> reload</div>
          <div><kbd>Ctrl</kbd> crouch</div><div><kbd>1</kbd><kbd>2</kbd> pistol / rifle</div>
          <div><kbd>Z</kbd> prone / crawl</div><div><kbd>H</kbd><kbd>J</kbd> take damage</div>
          <div><kbd>Tab</kbd> switch character</div><div><kbd>K</kbd> die · <kbd>G</kbd> get up</div>
        </div>
      </div>
      <div id="crosshair"><div class="dot"></div></div>
      <div id="fps"></div>
      <div id="lock-hint">Click to capture the mouse · Esc to release</div>
      <div id="overlay">
        <div class="box">
          <h1>Character &amp; movement prototype</h1>
          <p>Two Mixamo characters, retargeted motion-capture locomotion, aiming, shooting, crouch and prone.</p>
          <div class="bar"><div class="fill"></div></div>
          <div class="label">loading…</div>
        </div>
      </div>
    `;

    this.canvas = root.querySelector('#viewport');
    this.overlay = root.querySelector('#overlay');
    this.fill = root.querySelector('#overlay .fill');
    this.label = root.querySelector('#overlay .label');
    this.fpsEl = root.querySelector('#fps');
    this.crosshair = root.querySelector('#crosshair');
    this.lockHint = root.querySelector('#lock-hint');
    this.fields = {};
    root.querySelectorAll('[data-field]').forEach((el) => {
      this.fields[el.dataset.field] = el;
    });
  }

  setProgress(pct, label) {
    this.fill.style.width = `${Math.round(Math.min(1, pct) * 100)}%`;
    if (label) this.label.textContent = label;
  }

  hideOverlay() {
    this.overlay.classList.add('hidden');
  }

  showError(message) {
    this.overlay.classList.remove('hidden');
    this.overlay.classList.add('error');
    this.overlay.querySelector('h1').textContent = 'Failed to start';
    this.label.textContent = message;
    this.fill.style.width = '100%';
    this.fill.style.background = '#8a1d1d';
  }

  /** Shows which build of src/ is running, so a stale cached module is obvious. */
  setBuild(stamp) {
    const el = this.root.querySelector('[data-field="build"]');
    if (el) el.textContent = `build ${stamp}`;
  }

  update(state, { aiming, fps, locked }) {
    this.fields.character.textContent = state.character;
    this.fields.posture.textContent = state.posture;
    this.fields.aim.textContent = state.aim ? 'yes' : 'no';
    this.fields.weapon.textContent = state.weapon + (state.reloading ? ' (reloading)' : '');
    this.fields.ammo.textContent = state.ammo;
    if (this.fields.reserve) this.fields.reserve.textContent = state.reserve;
    this.fields.speed.textContent = `${state.speed.toFixed(2)} m/s`;
    this.fields.grounded.textContent = state.grounded ? 'yes' : 'no';
    this.fields.clip.textContent = `clip: ${state.clip}`;
    this.crosshair.classList.toggle('active', !!aiming);
    this.lockHint.classList.toggle('hidden', !!locked);
    this.fpsEl.textContent = `${Math.round(fps)} fps`;
  }
}
