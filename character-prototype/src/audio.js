/**
 * Weapon audio, played from downloaded CC0 recordings.
 *
 * Every sound in `public/assets/sfx/` is a real recording lifted from the
 * OpenSpades "free pak" (a CC0 drop-in asset pack; full source table in
 * `public/assets/ASSETS.md`), which itself sources its gunshots from the Free
 * Firearm Sound Library (CC0). Nothing here is synthesised: a shot is the
 * recorded gunshot for that weapon class, a reload is the recorded magazine
 * work, a pump is the recorded forend rack, a melee hit is the recorded impact.
 *
 * If a browser cannot decode Ogg/Opus (or the file fails to load) the class falls
 * back to the previous noise-burst synthesiser so the game is never silent, and
 * `status()` reports which mode is active.
 */

/** file -> [class, ...] with a per-class playback-rate jitter so repeats differ */
const SAMPLE_MAP = {
  rifle_fire: ['rifle', 'dmr', 'lmg'],
  smg_fire: ['smg', 'pistol', 'revolver', 'heavy'],
  shotgun_fire: ['shotgun'],
  sniper: ['sniper'],
  knife: ['knife'],
};

const SAMPLE_FILES = {
  rifle_fire: 'assets/sfx/rifle_fire.opus',
  smg_fire: 'assets/sfx/smg_fire.opus',
  shotgun_fire: 'assets/sfx/shotgun_fire.opus',
  shotgun_cock: 'assets/sfx/shotgun_cock.opus',
  rifle_reload: 'assets/sfx/rifle_reload.opus',
  smg_reload: 'assets/sfx/smg_reload.opus',
  shotgun_reload: 'assets/sfx/shotgun_reload.opus',
  dry_fire: 'assets/sfx/dry_fire.opus',
  switch: 'assets/sfx/switch.opus',
  melee_hit: 'assets/sfx/melee_hit.opus',
  impact_flesh: 'assets/sfx/impact_flesh.opus',
  impact_ricochet: 'assets/sfx/impact_ricochet.opus',
};

export class WeaponAudio {
  constructor() {
    const Ctx = typeof window !== 'undefined' ? (window.AudioContext || window.webkitAudioContext) : null;
    this.ctx = null;
    this.master = null;
    this.enabled = !!Ctx;
    if (Ctx) {
      try {
        this.ctx = new Ctx();
        this.master = this.ctx.createGain();
        this.master.gain.value = 0.22;
        this.master.connect(this.ctx.destination);
        this._noise = this._makeNoiseBuffer();
      } catch {
        this.enabled = false;
      }
    }
  }

  resume() {
    if (this.ctx && this.ctx.state === 'suspended') this.ctx.resume();
  }

  /**
   * Downloads and decodes every recorded sample. Safe to call once at boot: the
   * context is created suspended until the user clicks (browser autoplay rules),
   * decoding still works while suspended.
   */
  async loadSamples(onProgress) {
    if (!this.ctx) return { loaded: [], failed: Object.keys(SAMPLE_FILES) };
    this.samples = this.samples || {};
    const loaded = [];
    const failed = [];
    const names = Object.keys(SAMPLE_FILES);
    for (let i = 0; i < names.length; i += 1) {
      const name = names[i];
      try {
        const response = await fetch(SAMPLE_FILES[name]);
        const data = await response.arrayBuffer();
        this.samples[name] = await this.ctx.decodeAudioData(data);
        loaded.push(name);
      } catch (error) {
        failed.push({ name, error: String(error && error.message ? error.message : error) });
      }
      if (onProgress) onProgress(i + 1, names.length, name);
    }
    this.sampleMode = loaded.length > 0;
    return { loaded, failed };
  }

  /** Plays a decoded sample through a one-shot buffer source. */
  _sample(name, { gain = 1, rate = 1 } = {}) {
    const buffer = this.samples && this.samples[name];
    if (!buffer || !this.ctx) return false;
    const src = this.ctx.createBufferSource();
    src.buffer = buffer;
    src.playbackRate.value = rate;
    const g = this.ctx.createGain();
    g.gain.value = gain;
    src.connect(g).connect(this.master);
    src.start();
    return true;
  }

  /** Which playback path is live: recorded samples or the synth fallback. */
  status() {
    return {
      mode: this.sampleMode ? 'recorded' : 'synth',
      samples: this.samples ? Object.keys(this.samples) : [],
    };
  }

  _makeNoiseBuffer() {
    const length = Math.floor(this.ctx.sampleRate * 0.5);
    const buffer = this.ctx.createBuffer(1, length, this.ctx.sampleRate);
    const data = buffer.getChannelData(0);
    for (let i = 0; i < length; i += 1) data[i] = Math.random() * 2 - 1;
    return buffer;
  }

  /** Plays the shot for a weapon definition. */
  play(def) {
    if (!this.enabled || !this.ctx) return;
    // recorded gunshot for this weapon class, if the asset decoded
    const file = this._sampleFor(def.sound);
    if (file && this._sample(file, { gain: 0.9, rate: 0.95 + Math.random() * 0.1 })) return;
    const profile = PROFILES[def.sound] || PROFILES.rifle;
    const ctx = this.ctx;
    const now = ctx.currentTime;

    // body: filtered noise burst
    const noise = ctx.createBufferSource();
    noise.buffer = this._noise;
    noise.playbackRate.value = profile.rate;

    const filter = ctx.createBiquadFilter();
    filter.type = 'lowpass';
    filter.frequency.setValueAtTime(profile.cutoff, now);
    filter.frequency.exponentialRampToValueAtTime(Math.max(200, profile.cutoff * 0.18), now + profile.decay);

    const gain = ctx.createGain();
    gain.gain.setValueAtTime(profile.gain, now);
    gain.gain.exponentialRampToValueAtTime(0.0008, now + profile.decay);

    noise.connect(filter).connect(gain).connect(this.master);
    noise.start(now);
    noise.stop(now + profile.decay + 0.02);

    // crack: short high transient
    if (profile.crack > 0) {
      const osc = ctx.createOscillator();
      osc.type = 'square';
      osc.frequency.setValueAtTime(profile.crackFreq, now);
      osc.frequency.exponentialRampToValueAtTime(60, now + 0.09);
      const g2 = ctx.createGain();
      g2.gain.setValueAtTime(profile.crack, now);
      g2.gain.exponentialRampToValueAtTime(0.001, now + 0.1);
      osc.connect(g2).connect(this.master);
      osc.start(now);
      osc.stop(now + 0.12);
    }
  }

  _sampleFor(sound) {
    for (const [file, classes] of Object.entries(SAMPLE_MAP)) {
      if (classes.includes(sound)) return file;
    }
    return null;
  }

  /** Mechanical click for bolt / pump / magazine work. */
  click(kind = 'mag') {
    if (!this.enabled || !this.ctx) return;
    const file = kind === 'pump' ? 'shotgun_cock'
      : kind === 'shotgun' ? 'shotgun_reload'
        : kind === 'smg' ? 'smg_reload'
          : kind === 'dry' ? 'dry_fire'
            : 'rifle_reload';
    if (this._sample(file, { gain: 0.7, rate: 0.9 + Math.random() * 0.2 })) return;
    const ctx = this.ctx;
    const now = ctx.currentTime;
    const osc = ctx.createOscillator();
    osc.type = 'triangle';
    osc.frequency.setValueAtTime(kind === 'bolt' ? 780 : 420, now);
    osc.frequency.exponentialRampToValueAtTime(kind === 'bolt' ? 260 : 160, now + 0.06);
    const gain = ctx.createGain();
    gain.gain.setValueAtTime(0.12, now);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.08);
    osc.connect(gain).connect(this.master);
    osc.start(now);
    osc.stop(now + 0.09);
  }

  /** Recorded melee impact (falls back to a filtered whoosh). */
  swoosh(hit = false) {
    if (!this.enabled || !this.ctx) return;
    if (hit && this._sample('melee_hit', { gain: 0.8, rate: 0.95 + Math.random() * 0.15 })) return;
    if (!hit && this._sample('shotgun_cock', { gain: 0.25, rate: 1.6 })) return;
    const ctx = this.ctx;
    const now = ctx.currentTime;
    const noise = ctx.createBufferSource();
    noise.buffer = this._noise;
    noise.playbackRate.value = 0.6;
    const filter = ctx.createBiquadFilter();
    filter.type = 'bandpass';
    filter.frequency.setValueAtTime(900, now);
    filter.frequency.exponentialRampToValueAtTime(2600, now + 0.12);
    filter.Q.value = 1.2;
    const gain = ctx.createGain();
    gain.gain.setValueAtTime(0.001, now);
    gain.gain.exponentialRampToValueAtTime(0.09, now + 0.05);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.22);
    noise.connect(filter).connect(gain).connect(this.master);
    noise.start(now);
    noise.stop(now + 0.25);
  }

  /** Recorded bullet impact on a target (flesh/plate hit) or a miss ricochet. */
  impact(hit = true) {
    if (!this.enabled || !this.ctx) return;
    this._sample(hit ? 'impact_flesh' : 'impact_ricochet', {
      gain: hit ? 0.55 : 0.3, rate: 0.9 + Math.random() * 0.2,
    });
  }

  /** Weapon change: the recorded sling/switch sound. */
  equip() {
    if (!this.enabled || !this.ctx) return;
    this._sample('switch', { gain: 0.6, rate: 0.95 + Math.random() * 0.1 });
  }
}

const PROFILES = {
  pistol: { rate: 1.4, cutoff: 5200, decay: 0.16, gain: 0.5, crack: 0.25, crackFreq: 900 },
  heavy: { rate: 0.9, cutoff: 3000, decay: 0.3, gain: 0.75, crack: 0.35, crackFreq: 520 },
  suppressed: { rate: 1.1, cutoff: 1400, decay: 0.1, gain: 0.22, crack: 0.05, crackFreq: 380 },
  smg: { rate: 1.5, cutoff: 6200, decay: 0.12, gain: 0.4, crack: 0.18, crackFreq: 1100 },
  rifle: { rate: 1.0, cutoff: 4200, decay: 0.22, gain: 0.6, crack: 0.3, crackFreq: 780 },
  battle: { rate: 0.85, cutoff: 3400, decay: 0.3, gain: 0.75, crack: 0.35, crackFreq: 640 },
  sniper: { rate: 0.7, cutoff: 2800, decay: 0.45, gain: 0.9, crack: 0.45, crackFreq: 480 },
  shotgun: { rate: 0.7, cutoff: 2200, decay: 0.38, gain: 0.95, crack: 0.3, crackFreq: 300 },
  lmg: { rate: 0.9, cutoff: 3200, decay: 0.26, gain: 0.8, crack: 0.28, crackFreq: 560 },
  knife: { rate: 0.6, cutoff: 1800, decay: 0.1, gain: 0.1, crack: 0, crackFreq: 200 },
};
