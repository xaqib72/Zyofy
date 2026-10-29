import { WEAPON_LIST } from './weaponCatalog.js';

/**
 * Minimal DOM HUD: loading progress, live state read-out, a compact weapon
 * selector, crosshair, hit counter. No menus, no game UI - this exists so the
 * movement and weapon states can be verified while testing.
 */
export class Hud {
  constructor(root, { onSelectWeapon } = {}) {
    this.root = root;
    this.onSelectWeapon = onSelectWeapon || (() => {});
    root.innerHTML = `
      <canvas id="viewport"></canvas>
      <div id="state" class="panel">
        <h1>Character test bed</h1>
        <div class="row"><span>Character</span><span data-field="character">-</span></div>
        <div class="row"><span>Camera</span><span data-field="mode">-</span></div>
        <div class="row"><span>Stance</span><span data-field="posture">-</span></div>
        <div class="row"><span>Aiming</span><span data-field="aim">-</span></div>
        <div class="row"><span>Weapon</span><span data-field="weapon">-</span></div>
        <div class="row"><span>Ammo</span><span data-field="ammo">-</span></div>
        <div class="row"><span>Hits</span><span data-field="hits">0</span></div>
        <div class="row"><span>Last hit</span><span data-field="lastHit">-</span></div>
        <div class="row"><span>Speed</span><span data-field="speed">-</span></div>
        <div class="clip" data-field="clip">clip: -</div>
        <div class="build" data-field="build">build -</div>
      </div>
      <div id="weapons" class="panel">
        <h2>Weapons <span data-field="weaponCount" class="count">-</span></h2>
        <div class="weapon-list"></div>
      </div>
      <div id="controls" class="panel">
        <h2>Controls</h2>
        <div class="grid">
          <div><kbd>W</kbd><kbd>A</kbd><kbd>S</kbd><kbd>D</kbd> move</div><div><kbd>Mouse</kbd> look / aim</div>
          <div><kbd>Shift</kbd> sprint</div><div><kbd>RMB</kbd> aim</div>
          <div><kbd>Alt</kbd> walk</div><div><kbd>LMB</kbd> shoot</div>
          <div><kbd>Space</kbd> jump</div><div><kbd>R</kbd> reload</div>
          <div><kbd>Ctrl</kbd> crouch</div><div><kbd>Z</kbd> prone / belly crawl</div>
          <div><kbd>V</kbd> FPS / TPS</div><div><kbd>Q</kbd> next weapon</div>
          <div><kbd>1</kbd>–<kbd>9</kbd> weapons</div><div><kbd>E</kbd> refill ammo</div>
          <div><kbd>T</kbd> reset targets</div><div><kbd>Tab</kbd> switch character</div>
          <div><kbd>H</kbd><kbd>J</kbd> damage</div><div><kbd>K</kbd> die · <kbd>G</kbd> get up</div>
        </div>
      </div>
      <div id="crosshair"><div class="dot"></div></div>
      <div id="fps"></div>
      <div id="lock-hint">Click to capture the mouse · Esc to release</div>
      <div id="overlay">
        <div class="box">
          <h1>Character &amp; movement prototype</h1>
          <p>Two Mixamo characters, retargeted motion-capture locomotion, aiming, shooting, crouch and belly-crawl prone.</p>
          <div class="bar"><div class="fill"></div></div>
          <div class="label">starting…</div>
        </div>
      </div>
    `;

    this.canvas = root.querySelector('#viewport');
    this.overlay = root.querySelector('#overlay');
    this.fill = root.querySelector('.bar .fill');
    this.label = root.querySelector('.label');
    this.lockHint = root.querySelector('#lock-hint');
    this.list = root.querySelector('.weapon-list');

    this.fields = {};
    for (const el of root.querySelectorAll('[data-field]')) {
      this.fields[el.dataset.field] = el;
    }

    this._buildWeaponList();
  }

  _buildWeaponList() {
    this.list.innerHTML = '';
    WEAPON_LIST.forEach((def, index) => {
      const row = document.createElement('button');
      row.type = 'button';
      row.className = 'weapon';
      row.dataset.weapon = def.id;
      row.innerHTML = `<span class="idx">${index + 1}</span><span class="name">${def.label}</span>`;
      row.addEventListener('click', () => this.onSelectWeapon(def.id));
      this.list.appendChild(row);
    });
    if (this.fields.weaponCount) this.fields.weaponCount.textContent = String(WEAPON_LIST.length);
  }

  setBuild(stamp) {
    if (this.fields.build) this.fields.build.textContent = `build ${stamp}`;
  }

  setProgress(p, label) {
    this.fill.style.width = `${Math.round(p * 100)}%`;
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

  update(state, { aiming, fps, locked, range }) {
    this.fields.character.textContent = state.character;
    if (this.fields.mode) this.fields.mode.textContent = state.mode === 'fps' ? 'first person' : 'third person';
    this.fields.posture.textContent = state.posture;
    this.fields.aim.textContent = state.aim ? 'yes' : 'no';
    this.fields.weapon.textContent = `${state.weaponIndex}/${state.weaponCount} ${state.weapon}`;
    this.fields.ammo.textContent = state.ammo;
    this.fields.speed.textContent = `${state.speed.toFixed(2)} m/s`;
    this.fields.clip.textContent = `clip: ${state.clip}${state.action !== '-' ? ` · action: ${state.action}` : ''}`;
    if (this.fields.hits) this.fields.hits.textContent = String(range ? range.hits : 0);
    if (this.fields.lastHit) {
      this.fields.lastHit.textContent = range && range.last ? `${range.last.label} · ${range.last.damage} dmg` : '-';
    }

    for (const row of this.list.children) {
      row.classList.toggle('active', row.dataset.weapon === state.weaponId);
    }
    const active = this.list.querySelector('.weapon.active');
    if (active && this.list.scrollHeight > this.list.clientHeight + 4) {
      const top = active.offsetTop - this.list.clientHeight / 2;
      this.list.scrollTop = Math.max(0, top);
    }

    this.root.querySelector('#crosshair').classList.toggle('aim', !!aiming);
    this.root.querySelector('#crosshair').classList.toggle('hidden', state.posture === 'prone' && !aiming);
    if (this.fields.fps) this.fields.fps.textContent = `${fps.toFixed(0)} fps`;
    this.lockHint.classList.toggle('hidden', locked);
  }
}
