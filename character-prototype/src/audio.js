/**
 * Procedural weapon audio.
 *
 * No free sound library was reachable from this environment (Freesound needs an
 * account, most "free" SFX sites are paid or hotlink-protected), so the gunshots
 * are synthesised with the Web Audio API instead of pretending a downloaded pack
 * exists. Each weapon class has its own synthesis profile - a suppressed pistol
 * is a short filtered thump, a shotgun is a heavy noise burst with a long tail, a
 * sniper rifle cracks and echoes, a knife is a filtered whoosh - so the weapons
 * are audibly different from one another.
 *
 * The class degrades to a no-op when there is no AudioContext (headless tests).
 */
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

  /** Mechanical click for bolt / pump / magazine work. */
  click(kind = 'mag') {
    if (!this.enabled || !this.ctx) return;
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

  /** Filtered whoosh for melee swings. */
  swoosh() {
    if (!this.enabled || !this.ctx) return;
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
