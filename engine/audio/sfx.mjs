#!/usr/bin/env node
/*
 * MotionForge synthesised sound design (cinematic kit; no cartoon sounds).
 * Usage: node sfx.mjs <cuesheet.json> <out.wav>
 * cuesheet = {
 *   duration, sampleRate?: 48000,
 *   music:    [{t0, t1, mood, gain?}],        // dark_drone | tense_strings | sparse_piano | pulse_synth | epic_swell
 *   ambience: [{t0, t1, kind, gain?}],        // room_tone | hallway | outdoors | rain | night | office | server
 *   cues:     [{t, kind, gain?, dur?}],       // boom | subdrop | braam | riser | reverse_swell | whoosh | hit_metal | tick |
 *                                             // heartbeat | breath | footsteps | door | paper | click | low_hit | button
 *   silences: [[t0, t1]]                      // strategic silence: music+ambience dipped
 * }
 * Writes a mono 16-bit WAV "bed" (music + ambience + sfx). Ducking under the voice and loudness
 * normalisation happen afterwards in FFmpeg.
 */
import fs from 'node:fs';

const sheet = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const SR = sheet.sampleRate || 48000;
const N = Math.ceil((sheet.duration + 0.5) * SR);
const out = new Float32Array(N);

/* ---------- primitives ---------- */
let seed = 1234567;
const rnd = () => { seed = (seed * 1664525 + 1013904223) >>> 0; return seed / 4294967296 * 2 - 1; };
const TAU = Math.PI * 2;
const db = d => Math.pow(10, d / 20);
const midi = m => 440 * Math.pow(2, (m - 69) / 12);
function add(buf, at, gain = 1) { const o = Math.round(at * SR); for (let i = 0; i < buf.length; i++) { const j = o + i; if (j >= 0 && j < N) out[j] += buf[i] * gain; } }
function lp(buf, fc) { const a = Math.exp(-TAU * fc / SR); let y = 0; for (let i = 0; i < buf.length; i++) { y = (1 - a) * buf[i] + a * y; buf[i] = y; } return buf; }
function hp(buf, fc) { const a = Math.exp(-TAU * fc / SR); let y = 0, x1 = 0; for (let i = 0; i < buf.length; i++) { const x = buf[i]; y = a * (y + x - x1); x1 = x; buf[i] = y; } return buf; }
function bp(buf, lo, hi) { return lp(hp(buf, lo), hi); }
function env(n, a, d, shape = 1) { const e = new Float32Array(n), A = Math.max(1, Math.round(a * SR)); for (let i = 0; i < n; i++) e[i] = i < A ? i / A : Math.pow(Math.max(0, 1 - (i - A) / Math.max(1, n - A)), shape); return e; }
function mul(buf, e) { for (let i = 0; i < buf.length; i++) buf[i] *= e[i]; return buf; }
function noise(n) { const b = new Float32Array(n); for (let i = 0; i < n; i++) b[i] = rnd(); return b; }
function brown(n) { const b = new Float32Array(n); let y = 0; for (let i = 0; i < n; i++) { y = (y + rnd() * 0.02) * 0.998; b[i] = y * 8; } return b; }
function sine(n, f0, f1 = f0, curve = 1) { const b = new Float32Array(n); let ph = 0; for (let i = 0; i < n; i++) { const k = Math.pow(i / n, curve), f = f0 + (f1 - f0) * k; ph += TAU * f / SR; b[i] = Math.sin(ph); } return b; }
function saw(n, f, detune = 0) { const b = new Float32Array(n); let p1 = 0, p2 = 0; for (let i = 0; i < n; i++) { p1 = (p1 + f / SR) % 1; p2 = (p2 + f * (1 + detune) / SR) % 1; b[i] = (p1 * 2 - 1 + p2 * 2 - 1) / 2; } return b; }
function reverb(buf, tail = 1.6, mix = 0.3) {
  const n = buf.length + Math.round(tail * SR), o = new Float32Array(n); o.set(buf);
  const taps = [0.0297, 0.0371, 0.0411, 0.0437, 0.053, 0.067], g = Math.pow(0.001, 1 / (tail / 0.045));
  for (const d of taps) { const D = Math.round(d * SR); for (let i = D; i < n; i++) o[i] += o[i - D] * g * mix / taps.length * 2.2; }
  return o;
}
function sec(s) { return Math.max(1, Math.round(s * SR)); }

/* ---------- sound effects ---------- */
const FX = {
  boom: () => { const n = sec(2.4); const b = sine(n, 70, 32, 0.4); mul(b, env(n, 0.005, 0, 2.2)); const c = mul(lp(noise(sec(0.25)), 900), env(sec(0.25), 0.002, 0, 3)); for (let i = 0; i < c.length; i++) b[i] += c[i] * 0.5; return reverb(b, 2.2, 0.25); },
  subdrop: () => { const n = sec(2.0); return mul(sine(n, 90, 28, 0.6), env(n, 0.01, 0, 1.4)); },
  low_hit: () => { const n = sec(1.2); const b = sine(n, 55, 40); mul(b, env(n, 0.003, 0, 3)); return reverb(b, 1.4, 0.2); },
  braam: () => { const n = sec(3.2); const b = new Float32Array(n); for (const [f, g] of [[43.65, 1], [87.3, 0.6], [65.4, 0.5], [130.8, 0.25]]) { const s = saw(n, f, 0.004); for (let i = 0; i < n; i++) b[i] += s[i] * g; } lp(b, 700); mul(b, env(n, 0.06, 0, 1.3)); return reverb(b, 2.5, 0.3); },
  riser: (d = 3) => { const n = sec(d); const b = bp(noise(n), 300, 4000); const s = sine(n, 120, 900, 2); for (let i = 0; i < n; i++) b[i] = b[i] * 0.6 + s[i] * 0.25; return mul(b, env(n, d * 0.97, 0, 1)); },
  reverse_swell: (d = 2) => { const n = sec(d); const b = reverb(mul(lp(noise(sec(0.4)), 2500), env(sec(0.4), 0.002, 0, 2)), d, 0.6).slice(0, n); b.reverse(); return b; },
  whoosh: (d = 0.55) => { const n = sec(d); const b = noise(n); let a = 0; for (let i = 0; i < n; i++) { const k = i / n, fc = 200 + Math.sin(k * Math.PI) * 2200; const al = Math.exp(-TAU * fc / SR); a = (1 - al) * b[i] + al * a; b[i] = a * Math.sin(k * Math.PI) ** 2; } return hp(b, 120); },
  hit_metal: () => { const n = sec(1.6); const b = new Float32Array(n); for (const [f, g] of [[523, 1], [1247, 0.6], [2093, 0.35], [3011, 0.2]]) { const s = sine(n, f); for (let i = 0; i < n; i++) b[i] += s[i] * g; } mul(b, env(n, 0.001, 0, 4)); return reverb(b, 1.8, 0.35); },
  tick: () => { const n = sec(0.05); return mul(hp(noise(n), 2500), env(n, 0.0005, 0, 6)); },
  click: () => { const n = sec(0.04); return mul(hp(noise(n), 1500), env(n, 0.0005, 0, 5)); },
  heartbeat: () => { const n = sec(0.9), b = new Float32Array(n); for (const [o, g] of [[0, 1], [0.24, 0.7]]) { const m = sec(0.18), s = mul(sine(m, 62, 40), env(m, 0.004, 0, 2.5)); const O = sec(o); for (let i = 0; i < m && O + i < n; i++) b[O + i] += s[i] * g; } return b; },
  breath: () => { const n = sec(1.1); return mul(bp(noise(n), 400, 2200), env(n, 0.45, 0, 2)); },
  footsteps: (d = 2) => { const n = sec(d), b = new Float32Array(n); for (let t = 0.05; t < d; t += 0.52) { const m = sec(0.12), s = mul(lp(noise(m), 700), env(m, 0.002, 0, 4)); const O = sec(t); for (let i = 0; i < m && O + i < n; i++) b[O + i] += s[i]; } return b; },
  door: () => { const n = sec(1.0); const b = mul(sine(n, 140, 90), env(n, 0.005, 0, 3)); const c = mul(lp(noise(sec(0.3)), 400), env(sec(0.3), 0.002, 0, 3)); for (let i = 0; i < c.length; i++) b[i] += c[i]; return reverb(b, 1.0, 0.25); },
  paper: () => { const n = sec(0.5); return mul(bp(noise(n), 1500, 6000), env(n, 0.03, 0, 1.5)); },
  button: () => { const n = sec(2.2); const b = new Float32Array(n); for (const [m, g] of [[45, 1], [52, 0.5], [57, 0.4]]) { const s = sine(n, midi(m)); for (let i = 0; i < n; i++) b[i] += s[i] * g; } mul(b, env(n, 0.004, 0, 2.6)); return reverb(b, 2, 0.3); },
};

/* ---------- music beds ---------- */
function music(mood, d) {
  const n = sec(d), b = new Float32Array(n);
  const root = 38; // D
  switch (mood) {
    case 'dark_drone': {
      for (const [m, g] of [[root, 1], [root + 7, 0.5], [root + 12, 0.3]]) { const s = saw(n, midi(m), 0.003); for (let i = 0; i < n; i++) b[i] += s[i] * g; }
      lp(b, 380); for (let i = 0; i < n; i++) b[i] *= 0.7 + 0.3 * Math.sin(TAU * 0.07 * i / SR); break;
    }
    case 'tense_strings': {
      const chord = [root + 12, root + 15, root + 19, root + 24];
      for (const m of chord) { const s = saw(n, midi(m), 0.005); for (let i = 0; i < n; i++) b[i] += s[i] * 0.3 * (0.8 + 0.2 * Math.sin(TAU * 5.5 * i / SR)); }
      lp(b, 1400); break;
    }
    case 'sparse_piano': {
      const notes = [root + 26, root + 22, root + 19, root + 24, root + 26, root + 27, root + 22, root + 19];
      for (let k = 0, t = 0; t < d; k++, t += 1.6) { const m = sec(3), s = new Float32Array(m), f = midi(notes[k % notes.length]); for (let i = 0; i < m; i++) { const x = i / SR; s[i] = (Math.sin(TAU * f * x) + 0.4 * Math.sin(TAU * 2 * f * x) + 0.15 * Math.sin(TAU * 3 * f * x)) * Math.exp(-x * 1.6); } const O = sec(t); for (let i = 0; i < m && O + i < n; i++) b[O + i] += s[i] * 0.5; }
      const pad = lp(saw(n, midi(root + 12), 0.004), 300); for (let i = 0; i < n; i++) b[i] += pad[i] * 0.25; break;
    }
    case 'pulse_synth': {
      const bpm = 100, step = 60 / bpm / 2;
      for (let t = 0; t < d; t += step) { const m = sec(step * 0.9), s = mul(lp(saw(m, midi(root + (Math.round(t / step) % 8 === 7 ? 3 : 0)), 0.01), 900), env(m, 0.005, 0, 2)); const O = sec(t); for (let i = 0; i < m && O + i < n; i++) b[O + i] += s[i] * 0.6; }
      const dr = lp(saw(n, midi(root - 12)), 200); for (let i = 0; i < n; i++) b[i] += dr[i] * 0.5; break;
    }
    case 'epic_swell': {
      for (const [m, g] of [[root, 0.8], [root + 7, 0.5], [root + 12, 0.5], [root + 15, 0.35], [root + 19, 0.3]]) { const s = saw(n, midi(m), 0.004); for (let i = 0; i < n; i++) b[i] += s[i] * g; }
      lp(b, 1800); for (let i = 0; i < n; i++) b[i] *= Math.min(1, (i / n) * 1.6); break;
    }
    default: return music('dark_drone', d);
  }
  return b;
}
function ambience(kind, d) {
  const n = sec(d);
  switch (kind) {
    case 'hallway': { const b = bp(noise(n), 250, 1600); for (let i = 0; i < n; i++) b[i] *= 0.4 + 0.6 * Math.abs(Math.sin(TAU * 0.3 * i / SR + Math.sin(TAU * 0.11 * i / SR) * 3)); return b; }
    case 'outdoors': { const b = lp(noise(n), 1200); const w = brown(n); for (let i = 0; i < n; i++) b[i] = b[i] * 0.3 + w[i] * 0.7; return b; }
    case 'rain': return hp(noise(n), 1800);
    case 'night': { const b = lp(brown(n), 400); return b; }
    case 'server': { const b = lp(noise(n), 3000); const h = sine(n, 120); for (let i = 0; i < n; i++) b[i] = b[i] * 0.5 + h[i] * 0.2; return b; }
    case 'office': case 'room_tone': default: return lp(brown(n), 600);
  }
}

/* ---------- assemble ---------- */
const XF = 0.8; // crossfade seconds for beds
function placeBed(buf, t0, t1, gain) {
  const n = buf.length, f = sec(XF);
  for (let i = 0; i < n; i++) { const k = Math.min(1, i / f, (n - i) / f); buf[i] *= k; }
  add(buf, t0, gain);
}
for (const m of sheet.music || []) placeBed(music(m.mood, Math.max(0.5, m.t1 - m.t0)), m.t0, m.t1, db(m.gain ?? -14));
for (const a of sheet.ambience || []) placeBed(ambience(a.kind, Math.max(0.5, a.t1 - a.t0)), a.t0, a.t1, db(a.gain ?? -24));
// strategic silence: dip the beds before cues are added
for (const [t0, t1] of sheet.silences || []) { const a = sec(t0), b = sec(t1), r = sec(0.15); for (let i = Math.max(0, a - r); i < Math.min(N, b + r); i++) { const k = i < a ? 1 - (i - (a - r)) / r : i > b ? (i - b) / r : 0; out[i] *= Math.max(0.03, Math.min(1, 1 - (1 - k))); } }
for (const c of sheet.cues || []) {
  const f = FX[c.kind]; if (!f) continue;
  add(f(c.dur), c.t, db(c.gain ?? -6));
}
// soft clip + write
let peak = 0; for (let i = 0; i < N; i++) { out[i] = Math.tanh(out[i] * 0.9); peak = Math.max(peak, Math.abs(out[i])); }
const pcm = Buffer.alloc(44 + N * 2);
pcm.write('RIFF', 0); pcm.writeUInt32LE(36 + N * 2, 4); pcm.write('WAVE', 8); pcm.write('fmt ', 12); pcm.writeUInt32LE(16, 16);
pcm.writeUInt16LE(1, 20); pcm.writeUInt16LE(1, 22); pcm.writeUInt32LE(SR, 24); pcm.writeUInt32LE(SR * 2, 28); pcm.writeUInt16LE(2, 32); pcm.writeUInt16LE(16, 34);
pcm.write('data', 36); pcm.writeUInt32LE(N * 2, 40);
for (let i = 0; i < N; i++) pcm.writeInt16LE(Math.round(Math.max(-1, Math.min(1, out[i])) * 32767), 44 + i * 2);
fs.writeFileSync(process.argv[3], pcm);
process.stdout.write(JSON.stringify({ type: 'done', samples: N, sampleRate: SR, peak: +peak.toFixed(3), kinds: Object.keys(FX) }) + '\n');
