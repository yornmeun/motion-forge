/*
 * MotionForge — Kikitoons-compatible engine: core.js
 *
 * Clean-room implementation of the API documented in the kikitoons-video-maker-v1 skill.
 * It is NOT the original Kikitoons engine. When the original engine is supplied through
 * KIKITOONS_ENGINE_PATH, MotionForge loads that engine instead and this file is unused.
 *
 * World: 1080x1920. Characters use local units: white oval head rx 100 / ry 108 centred at
 * (0,0); shoulders at ±46,140; hips ±26,250; feet ≈ ±32,352. (x, y) on a character is the
 * head centre in world coordinates and s is the scale.
 */
'use strict';

const W = 1080, H = 1920, OW = 9;
const INK = '#16131a';

/* ---------- maths ---------- */
const clamp = (v, a = 0, b = 1) => Math.max(a, Math.min(b, v));
const lerp = (a, b, k) => a + (b - a) * k;
const seg = (t, a, b) => clamp((t - a) / (b - a));
const E = {
  lin: k => clamp(k),
  i: k => { k = clamp(k); return k * k * k; },
  o: k => { k = clamp(k); return 1 - Math.pow(1 - k, 3); },
  io: k => { k = clamp(k); return k < 0.5 ? 4 * k * k * k : 1 - Math.pow(-2 * k + 2, 3) / 2; },
  ob: k => { k = clamp(k); const c1 = 1.70158, c3 = c1 + 1; return 1 + c3 * Math.pow(k - 1, 3) + c1 * Math.pow(k - 1, 2); },
  anti: k => { k = clamp(k); const c = 1.6; return k * k * ((c + 1) * k - c); },
  heavy: k => { k = clamp(k); return k < 0.5 ? 16 * Math.pow(k, 5) : 1 - Math.pow(-2 * k + 2, 5) / 2; },
};

function hash(n) { const s = Math.sin(n * 127.1 + 311.7) * 43758.5453; return s - Math.floor(s); }
/** Smooth 1-D value noise in [-1, 1]. */
function noise(x, seed = 0) {
  const i = Math.floor(x), f = x - i, u = f * f * (3 - 2 * f);
  return lerp(hash(i + seed * 57.3), hash(i + 1 + seed * 57.3), u) * 2 - 1;
}

/** Global time (s) a spoken word starts, from the verified caption timings (CAPS). n = nth match. */
function capTime(word, n = 0) {
  if (typeof CAPS === 'undefined') return null;
  const want = String(word).toLowerCase().replace(/[^a-z0-9']/g, '');
  let k = 0;
  for (const c of CAPS) for (const w of (c.words || [])) {
    if (String(w.w).toLowerCase().replace(/[^a-z0-9']/g, '') === want) { if (k === n) return w.start; k++; }
  }
  return null;
}
/** Scene-local time of a spoken word for scene index i (falls back to `fallback`). */
function capLocal(i, word, fallback = 0, n = 0) {
  const g = capTime(word, n);
  return g === null || typeof STORY === 'undefined' ? fallback : g - STORY.starts[i];
}

/* ---------- camera ---------- */
let __cam = { z: 1, cx: W / 2, cy: H / 2, rot: 0, sx: 0, sy: 0 };
function cam(ctx, c = {}) {
  const z = c.z ?? 1, cx = c.cx ?? W / 2, cy = c.cy ?? H / 2, rot = c.rot ?? 0;
  __cam = { z, cx, cy, rot, sx: c.sx ?? 0, sy: c.sy ?? 0 };
  ctx.setTransform(1, 0, 0, 1, 0, 0);
  ctx.translate(W / 2 + __cam.sx, H / 2 + __cam.sy);
  ctx.rotate(rot);
  ctx.scale(z, z);
  ctx.translate(-cx, -cy);
}
function currentCam() { return { ...__cam }; }

/** Keyframed camera: keys = [[time, {z, cx, cy, rot}], ...]. */
function camKeys(t, keys, ease = E.heavy) {
  if (!keys.length) return { z: 1, cx: W / 2, cy: H / 2, rot: 0 };
  const base = { z: 1, cx: W / 2, cy: H / 2, rot: 0 };
  if (t <= keys[0][0]) return { ...base, ...keys[0][1] };
  for (let k = 0; k < keys.length - 1; k++) {
    const [ta, a] = keys[k], [tb, b] = keys[k + 1];
    if (t <= tb) {
      const A = { ...base, ...a }, B = { ...base, ...A, ...b }, q = ease(seg(t, ta, tb));
      return { z: lerp(A.z, B.z, q), cx: lerp(A.cx, B.cx, q), cy: lerp(A.cy, B.cy, q), rot: lerp(A.rot, B.rot, q) };
    }
  }
  return { ...base, ...keys[keys.length - 1][1] };
}
/** Short, heavy shake around time t0. */
function shk(t, t0 = 0, amt = 18, dur = 0.35) {
  const k = seg(t, t0, t0 + dur);
  if (k <= 0 || k >= 1) return { sx: 0, sy: 0 };
  const d = (1 - k) * amt;
  return { sx: noise(t * 40, 1) * d, sy: noise(t * 40, 2) * d };
}
/** Slow handheld drift for tense shots. */
function handheld(t, amt = 6, seed = 0) {
  return { sx: noise(t * 0.7, seed + 3) * amt, sy: noise(t * 0.6, seed + 4) * amt, rot: noise(t * 0.5, seed + 5) * 0.004 };
}
/** Merge a camKeys result with handheld/shake offsets. */
function camMix(...parts) {
  const o = { z: 1, cx: W / 2, cy: H / 2, rot: 0, sx: 0, sy: 0 };
  for (const p of parts) {
    if (!p) continue;
    for (const k of ['z', 'cx', 'cy']) if (p[k] !== undefined) o[k] = p[k];
    o.rot += p.rot ?? 0; o.sx += p.sx ?? 0; o.sy += p.sy ?? 0;
  }
  return o;
}

/* ---------- drawing helpers ---------- */
function fillAll(ctx, color) {
  ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.fillStyle = color; ctx.fillRect(0, 0, W, H); ctx.restore();
  // also fill a large world rect for zoomed-out / rotated cameras
  ctx.save(); ctx.fillStyle = color; ctx.fillRect(-W, -H, W * 3, H * 3); ctx.restore();
}
function rr(ctx, x, y, w, h, r) {
  r = Math.min(r, w / 2, h / 2);
  ctx.beginPath(); ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
}
function shape(ctx, fill, stroke = INK, lw = OW) {
  if (fill) { ctx.fillStyle = fill; ctx.fill(); }
  if (stroke) { ctx.lineWidth = lw; ctx.strokeStyle = stroke; ctx.lineJoin = 'round'; ctx.lineCap = 'round'; ctx.stroke(); }
}
function line(ctx, pts, lw = OW, col = INK) {
  ctx.beginPath(); ctx.moveTo(pts[0][0], pts[0][1]);
  for (let i = 1; i < pts.length; i++) ctx.lineTo(pts[i][0], pts[i][1]);
  ctx.lineWidth = lw; ctx.strokeStyle = col; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.stroke();
}
function shade(hex, amt) {
  const n = parseInt(hex.slice(1), 16);
  let r = (n >> 16) & 255, g = (n >> 8) & 255, b = n & 255;
  const f = amt < 0 ? 0 : 255, p = Math.abs(amt);
  r = Math.round((f - r) * p + r); g = Math.round((f - g) * p + g); b = Math.round((f - b) * p + b);
  return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
}

/** Text in Poppins-Bold. opts: {stroke, sw, align, maxW, baseline, alpha}. */
function txt(ctx, s, x, y, size, fill = '#fff', o = {}) {
  ctx.save();
  ctx.font = `700 ${size}px Poppins, sans-serif`;
  ctx.textAlign = o.align || 'center'; ctx.textBaseline = o.baseline || 'middle';
  if (o.alpha !== undefined) ctx.globalAlpha *= o.alpha;
  const lines = o.maxW ? wrap(ctx, String(s), o.maxW) : [String(s)];
  const lh = size * 1.12, y0 = y - (lines.length - 1) * lh / 2;
  lines.forEach((ln, i) => {
    if (o.stroke) { ctx.lineWidth = o.sw || size * 0.12; ctx.strokeStyle = o.stroke; ctx.lineJoin = 'round'; ctx.strokeText(ln, x, y0 + i * lh); }
    ctx.fillStyle = fill; ctx.fillText(ln, x, y0 + i * lh);
  });
  ctx.restore();
  return lines.length;
}
function wrap(ctx, s, maxW) {
  const words = s.split(/\s+/), out = []; let cur = '';
  for (const w of words) { const n = cur ? cur + ' ' + w : w; if (ctx.measureText(n).width > maxW && cur) { out.push(cur); cur = w; } else cur = n; }
  if (cur) out.push(cur);
  return out;
}

/* ---------- faces ---------- */
/*
 * face: {lid 0..1, look:[dx,dy], brows, browLift, mouth, ma, blink, red, happyEyes, sweat}
 */
function drawFace(ctx, f = {}) {
  const look = f.look || [0, 0], lx = clamp(look[0], -1, 1) * 14, ly = clamp(look[1], -1, 1) * 10;
  const lid = clamp(Math.max(f.lid ?? 0, f.blink ?? 0));
  for (const side of [-1, 1]) {
    const ex = side * 38, ey = -8;
    ctx.save(); ctx.translate(ex, ey);
    if (f.happyEyes) {
      ctx.beginPath(); ctx.arc(0, 8, 16, Math.PI * 1.15, Math.PI * 1.85); ctx.lineWidth = OW; ctx.strokeStyle = INK; ctx.lineCap = 'round'; ctx.stroke();
    } else {
      ctx.beginPath(); ctx.ellipse(0, 0, 21, 25, 0, 0, Math.PI * 2); shape(ctx, '#fff', INK, OW * 0.75);
      ctx.save(); ctx.beginPath(); ctx.ellipse(0, 0, 21, 25, 0, 0, Math.PI * 2); ctx.clip();
      ctx.beginPath(); ctx.ellipse(lx, ly + 2, 11, 13, 0, 0, Math.PI * 2); ctx.fillStyle = INK; ctx.fill();
      ctx.beginPath(); ctx.arc(lx + 4, ly - 4, 3.6, 0, Math.PI * 2); ctx.fillStyle = '#fff'; ctx.fill();
      if (lid > 0) { ctx.fillStyle = '#fff'; ctx.fillRect(-26, -30, 52, 60 * lid); ctx.beginPath(); ctx.moveTo(-26, -30 + 60 * lid); ctx.lineTo(26, -30 + 60 * lid); ctx.lineWidth = OW * 0.75; ctx.strokeStyle = INK; ctx.stroke(); }
      ctx.restore();
    }
    // brows
    const lift = (f.browLift ?? 0) * -14;
    let a = 0, by = -40 + lift;
    switch (f.brows) {
      case 'angry': a = -0.42 * side; by += 6; break;
      case 'stern': a = -0.22 * side; by += 4; break;
      case 'sneaky': a = side < 0 ? -0.3 : 0.12; break;
      case 'raise': a = side < 0 ? 0 : 0.25; by -= side > 0 ? 10 : 0; break;
      case 'up': by -= 10; a = 0.1 * side; break;
      case 'worried': a = 0.38 * side; by -= 2; break;
      case 'flat': default: a = 0;
    }
    if (f.brows) {
      ctx.save(); ctx.translate(0, by); ctx.rotate(a); line(ctx, [[-17, 0], [17, 0]], OW * 1.05); ctx.restore();
    }
    ctx.restore();
  }
  if (f.red) { ctx.save(); ctx.globalAlpha = clamp(f.red) * 0.5; ctx.fillStyle = '#d8434a'; for (const s of [-1, 1]) { ctx.beginPath(); ctx.ellipse(s * 58, 30, 20, 11, 0, 0, Math.PI * 2); ctx.fill(); } ctx.restore(); }
  drawMouth(ctx, f.mouth || 'flat', f.ma ?? 0.5);
  if (f.sweat) { ctx.beginPath(); ctx.moveTo(84, -40); ctx.quadraticCurveTo(96, -14, 84, -8); ctx.quadraticCurveTo(72, -14, 84, -40); shape(ctx, '#9fd3f0', INK, 4); }
}
function drawMouth(ctx, m, ma) {
  const y = 46; ma = clamp(ma);
  ctx.save();
  switch (m) {
    case 'happy': ctx.beginPath(); ctx.arc(0, y - 10, 24, 0.15 * Math.PI, 0.85 * Math.PI); ctx.lineWidth = OW; ctx.strokeStyle = INK; ctx.lineCap = 'round'; ctx.stroke(); break;
    case 'grin': ctx.beginPath(); ctx.moveTo(-28, y - 4); ctx.quadraticCurveTo(0, y + 30, 28, y - 4); ctx.closePath(); shape(ctx, '#fff', INK, OW * 0.8); break;
    case 'smirk': ctx.beginPath(); ctx.moveTo(-18, y + 2); ctx.quadraticCurveTo(6, y + 8, 22, y - 8); ctx.lineWidth = OW; ctx.strokeStyle = INK; ctx.lineCap = 'round'; ctx.stroke(); break;
    case 'teeth': rr(ctx, -26, y - 10, 52, 22, 8); shape(ctx, '#fff', INK, OW * 0.8); line(ctx, [[-24, y + 1], [24, y + 1]], 3); break;
    case 'O': ctx.beginPath(); ctx.ellipse(0, y, 10 + ma * 6, 12 + ma * 10, 0, 0, Math.PI * 2); shape(ctx, '#5a2230', INK, OW * 0.8); break;
    case 'talk': { const h = 3 + ma * 26; ctx.beginPath(); ctx.ellipse(0, y, 18 + ma * 4, h / 2 + 2, 0, 0, Math.PI * 2); shape(ctx, ma > 0.08 ? '#5a2230' : INK, INK, OW * 0.8); break; }
    case 'sad': ctx.beginPath(); ctx.arc(0, y + 18, 20, 1.2 * Math.PI, 1.8 * Math.PI); ctx.lineWidth = OW; ctx.strokeStyle = INK; ctx.lineCap = 'round'; ctx.stroke(); break;
    case 'wavy': ctx.beginPath(); ctx.moveTo(-24, y); for (let i = 0; i <= 8; i++) ctx.lineTo(-24 + i * 6, y + (i % 2 ? -5 : 5)); ctx.lineWidth = OW * 0.7; ctx.strokeStyle = INK; ctx.stroke(); break;
    case 'chew': ctx.beginPath(); ctx.ellipse(0, y, 16, 4 + ma * 6, 0, 0, Math.PI * 2); shape(ctx, INK, INK, 4); break;
    case 'flat': default: line(ctx, [[-16, y], [16, y]], OW);
  }
  ctx.restore();
}

/* ---------- characters ---------- */
const ARMS = {
  rest: { L: [[-76, 194], [-82, 250]], R: [[76, 194], [82, 250]] },
  up: { L: [[-84, 96], [-92, 30]], R: [[84, 96], [92, 30]] },
  cheer: { L: [[-90, 110], [-120, 50]], R: [[90, 110], [120, 50]], fistL: true, fistR: true },
  fold: { L: [[-40, 196], [30, 186]], R: [[40, 196], [-30, 180]], frontL: true, frontR: true },
  point: { L: [[-76, 194], [-82, 250]], R: [[100, 150], [160, 140]] },
  think: { L: [[-76, 194], [-82, 250]], R: [[66, 200], [30, 70]], fistR: true, frontR: true },
  hips: { L: [[-92, 196], [-36, 238]], R: [[92, 196], [36, 238]] },
  shrug: { L: [[-96, 168], [-120, 120]], R: [[96, 168], [120, 120]] },
  phone: { L: [[-76, 194], [-82, 250]], R: [[60, 210], [26, 150]], frontR: true },
};

function drawArm(ctx, sx, arm, fist, col) {
  if (!arm) return;
  const [[ex, ey], [hx, hy]] = arm;
  line(ctx, [[sx, 140], [ex, ey], [hx, hy]], OW * 1.25, INK);
  line(ctx, [[sx, 140], [ex, ey], [hx, hy]], OW * 0.45, col);
  ctx.beginPath(); ctx.arc(hx, hy, fist ? 15 : 13, 0, Math.PI * 2); shape(ctx, '#fff', INK, OW * 0.8);
}

/** Kid character (Kiki and other kids). */
function drawChar(ctx, o = {}) {
  const s = o.s ?? 1, shirt = o.shirt || '#5b7bd5';
  const sq = o.sq ?? 0; // squash (+) / stretch (-), volume preserving
  const sxk = 1 + sq * 0.25, syk = 1 / sxk;
  ctx.save();
  ctx.translate(o.x ?? W / 2, (o.y ?? H / 2) + (o.bob ?? 0));
  ctx.scale(s * (o.flip ? -1 : 1) * sxk, s * syk);
  if (o.alpha !== undefined) ctx.globalAlpha *= o.alpha;
  const arms = o.arms || ARMS.rest;
  if (o.behindBody) o.behindBody(ctx);
  // back arms
  if (!arms.frontL) drawArm(ctx, -46, arms.L, arms.fistL, shirt);
  if (!arms.frontR) drawArm(ctx, 46, arms.R, arms.fistR, shirt);
  // legs
  if (!o.noLegs) {
    const lg = o.legs || {};
    const lL = lg.L || [[-26, 250], [-30, 300], [-32, 352]], lR = lg.R || [[26, 250], [30, 300], [32, 352]];
    for (const L of [lL, lR]) {
      line(ctx, L, OW * 1.3, INK); line(ctx, L, OW * 0.5, o.pants || '#2f3446');
      const f = L[L.length - 1]; rr(ctx, f[0] - 22, f[1] - 6, 44, 18, 9); shape(ctx, o.shoes || '#e9e4da', INK, OW * 0.7);
    }
  }
  // body (tee)
  ctx.beginPath();
  ctx.moveTo(-50, 126); ctx.quadraticCurveTo(0, 112, 50, 126); ctx.lineTo(56, 254); ctx.quadraticCurveTo(0, 264, -56, 254); ctx.closePath();
  shape(ctx, shirt, INK, OW);
  if (o.shirtMark) o.shirtMark(ctx);
  // head
  ctx.save();
  ctx.translate(0, o.headDY ?? 0); ctx.rotate(o.headRot ?? 0);
  if (o.back) {
    ctx.beginPath(); ctx.ellipse(0, 0, 100, 108, 0, 0, Math.PI * 2); shape(ctx, '#fff', INK, OW);
    if (o.hairBack) o.hairBack(ctx);
  } else {
    if (o.hair && o.hair !== 'none') drawHair(ctx, o.hair, o.hairCol || '#3a2a20', true);
    ctx.beginPath(); ctx.ellipse(0, 0, 100, 108, 0, 0, Math.PI * 2); shape(ctx, '#fff', INK, OW);
    if (o.freckles) { ctx.fillStyle = '#c98a5a'; for (const [fx, fy] of [[-62, 22], [-52, 32], [-70, 34], [62, 22], [52, 32], [70, 34]]) { ctx.beginPath(); ctx.arc(fx, fy, 3.6, 0, Math.PI * 2); ctx.fill(); } }
    drawFace(ctx, o.face || {});
    if (o.hair && o.hair !== 'none') drawHair(ctx, o.hair, o.hairCol || '#3a2a20', false);
  }
  if (o.cap) drawCap(ctx, o.cap, !!o.back);
  if (o.onHead) o.onHead(ctx);
  ctx.restore();
  // front arms
  if (arms.frontL) drawArm(ctx, -46, arms.L, arms.fistL, shirt);
  if (arms.frontR) drawArm(ctx, 46, arms.R, arms.fistR, shirt);
  if (o.handItem) { const h = (arms.R || ARMS.rest.R)[1]; ctx.save(); ctx.translate(h[0], h[1]); o.handItem(ctx); ctx.restore(); }
  if (o.after) o.after(ctx);
  ctx.restore();
}

function drawCap(ctx, cap, back) {
  const col = cap.color || '#c0392b';
  // backwards cap: dome on top, brim sticking out to the LEFT behind the head
  ctx.save();
  if (!back) {
    ctx.beginPath(); ctx.moveTo(-104, -40); ctx.quadraticCurveTo(-150, -58, -168, -40); ctx.quadraticCurveTo(-150, -26, -100, -26); ctx.closePath();
    shape(ctx, shade(col, -0.18), INK, OW * 0.9);
  }
  ctx.beginPath(); ctx.moveTo(-100, -36); ctx.bezierCurveTo(-96, -128, 96, -128, 100, -36); ctx.quadraticCurveTo(0, -56, -100, -36); ctx.closePath();
  shape(ctx, col, INK, OW);
  // strap window on the back of a backwards cap (front-facing view shows it on the forehead side)
  rr(ctx, -20, -54, 40, 16, 6); shape(ctx, '#fff', INK, OW * 0.6);
  ctx.beginPath(); ctx.arc(0, -112, 7, 0, Math.PI * 2); shape(ctx, shade(col, -0.25), INK, 4);
  ctx.restore();
}

function drawHair(ctx, kind, col, behind) {
  ctx.save();
  switch (kind) {
    case 'bob':
      if (behind) { ctx.beginPath(); ctx.ellipse(0, 6, 116, 114, 0, Math.PI * 0.95, Math.PI * 2.05); ctx.lineTo(116, 60); ctx.lineTo(-116, 60); ctx.closePath(); shape(ctx, col); }
      else { ctx.beginPath(); ctx.moveTo(-100, -30); ctx.bezierCurveTo(-90, -130, 90, -130, 100, -30); ctx.quadraticCurveTo(20, -70, -100, -30); shape(ctx, col); }
      break;
    case 'pony':
      if (behind) { ctx.beginPath(); ctx.ellipse(112, -30, 34, 70, 0.5, 0, Math.PI * 2); shape(ctx, col); }
      else { ctx.beginPath(); ctx.moveTo(-100, -24); ctx.bezierCurveTo(-96, -128, 96, -128, 100, -24); ctx.quadraticCurveTo(-10, -84, -100, -24); shape(ctx, col); }
      break;
    case 'buns':
      if (behind) for (const s of [-1, 1]) { ctx.beginPath(); ctx.arc(s * 82, -86, 34, 0, Math.PI * 2); shape(ctx, col); }
      else { ctx.beginPath(); ctx.moveTo(-100, -24); ctx.bezierCurveTo(-96, -126, 96, -126, 100, -24); ctx.quadraticCurveTo(0, -76, -100, -24); shape(ctx, col); }
      break;
    case 'long':
      if (behind) { rr(ctx, -118, -60, 236, 210, 60); shape(ctx, col); }
      else { ctx.beginPath(); ctx.moveTo(-104, 10); ctx.bezierCurveTo(-110, -134, 110, -134, 104, 10); ctx.quadraticCurveTo(40, -80, -104, 10); shape(ctx, col); }
      break;
    case 'side':
      if (!behind) { ctx.beginPath(); ctx.moveTo(-100, -20); ctx.bezierCurveTo(-100, -130, 100, -128, 100, -40); ctx.quadraticCurveTo(-30, -90, -100, -20); shape(ctx, col); }
      break;
    case 'spiky':
      if (!behind) { ctx.beginPath(); ctx.moveTo(-100, -36); for (let i = 0; i <= 8; i++) { const a = Math.PI + i * Math.PI / 8; ctx.lineTo(Math.cos(a) * (i % 2 ? 140 : 104), Math.sin(a) * (i % 2 ? 140 : 104) - 6); } ctx.quadraticCurveTo(0, -70, -100, -36); shape(ctx, col); }
      break;
    case 'curly':
      if (!behind) for (let i = 0; i < 9; i++) { const a = Math.PI * 1.05 + i * Math.PI * 0.9 / 8; ctx.beginPath(); ctx.arc(Math.cos(a) * 96, Math.sin(a) * 100, 30, 0, Math.PI * 2); shape(ctx, col, INK, OW * 0.7); }
      break;
    case 'beanie':
      if (!behind) { ctx.beginPath(); ctx.moveTo(-104, -30); ctx.bezierCurveTo(-100, -150, 100, -150, 104, -30); ctx.closePath(); shape(ctx, col); rr(ctx, -110, -50, 220, 30, 14); shape(ctx, shade(col, -0.2)); }
      break;
  }
  ctx.restore();
}

/** The established Kiki preset: backwards red cap (brim left), freckles, yellow tee. */
function kiki(o = {}) {
  return Object.assign({ shirt: '#f2c230', cap: { color: '#c62f2b' }, freckles: true, hair: 'none', pants: '#33415c', shoes: '#ece6da' }, o, { kiki: true });
}

/** Adult in a suit. Principal Karl = defaults (hair 'karl', grey suit, red tie). */
function drawSuit(ctx, o = {}) {
  const s = o.s ?? 1, suit = o.suit || '#6b7280', tie = o.tie || '#b3262e';
  ctx.save();
  ctx.translate(o.x ?? W / 2, (o.y ?? H / 2) + (o.bob ?? 0));
  ctx.scale(s * (o.flip ? -1 : 1), s);
  if (o.alpha !== undefined) ctx.globalAlpha *= o.alpha;
  const arms = o.arms || { L: [[-74, 230], [-80, 320]], R: [[74, 230], [80, 320]] };
  if (o.behindBody) o.behindBody(ctx);
  if (!arms.frontL) drawSuitArm(ctx, -66, arms.L, suit);
  if (!arms.frontR) drawSuitArm(ctx, 66, arms.R, suit);
  if (!o.noLegs) for (const sx of [-30, 30]) { line(ctx, [[sx, 330], [sx * 1.1, 420], [sx * 1.15, 500]], OW * 2.2, INK); line(ctx, [[sx, 330], [sx * 1.1, 420], [sx * 1.15, 500]], OW * 1.3, shade(suit, -0.25)); rr(ctx, sx * 1.15 - 28, 494, 56, 20, 10); shape(ctx, '#1d1b20'); }
  ctx.beginPath(); ctx.moveTo(-70, 128); ctx.quadraticCurveTo(0, 110, 70, 128); ctx.lineTo(80, 340); ctx.quadraticCurveTo(0, 352, -80, 340); ctx.closePath(); shape(ctx, suit);
  ctx.beginPath(); ctx.moveTo(-24, 122); ctx.lineTo(0, 210); ctx.lineTo(24, 122); ctx.closePath(); shape(ctx, '#f4f1ea', INK, OW * 0.7);
  ctx.beginPath(); ctx.moveTo(-9, 132); ctx.lineTo(9, 132); ctx.lineTo(14, 230); ctx.lineTo(0, 248); ctx.lineTo(-14, 230); ctx.closePath(); shape(ctx, tie, INK, OW * 0.6);
  line(ctx, [[-24, 122], [-6, 250]], OW * 0.6); line(ctx, [[24, 122], [6, 250]], OW * 0.6);
  ctx.save(); ctx.translate(0, o.headDY ?? 0); ctx.rotate(o.headRot ?? 0);
  ctx.beginPath(); ctx.ellipse(0, 0, 100, 108, 0, 0, Math.PI * 2); shape(ctx, '#fff', INK, OW);
  if (!o.back) {
    drawFace(ctx, o.face || { brows: 'stern' });
    if (o.glasses) for (const sx of [-38, 38]) { rr(ctx, sx - 30, -34, 60, 50, 14); shape(ctx, null, INK, OW * 0.6); }
    if (o.mustache) { ctx.beginPath(); ctx.ellipse(-14, 32, 18, 8, 0.2, 0, Math.PI * 2); ctx.ellipse(14, 32, 18, 8, -0.2, 0, Math.PI * 2); shape(ctx, o.hairCol || '#4a4a4a', INK, 4); }
  }
  const hk = o.hair ?? 'karl';
  const hc = o.hairCol || (hk === 'karl' ? '#3b2f2a' : '#9ca3af');
  if (hk === 'karl') { ctx.beginPath(); ctx.moveTo(-100, -34); ctx.bezierCurveTo(-100, -134, 100, -134, 100, -34); ctx.quadraticCurveTo(80, -84, 10, -86); ctx.quadraticCurveTo(-60, -84, -100, -34); shape(ctx, hc); }
  else if (hk === 'grey' || hk === 'greycap') { for (const sx of [-1, 1]) { ctx.beginPath(); ctx.ellipse(sx * 94, -26, 22, 40, 0, 0, Math.PI * 2); shape(ctx, hc, INK, OW * 0.7); } if (hk === 'greycap') { ctx.beginPath(); ctx.moveTo(-100, -40); ctx.bezierCurveTo(-96, -132, 96, -132, 100, -40); ctx.closePath(); shape(ctx, '#2d3a4a'); } }
  else if (hk === 'bunGrey') { ctx.beginPath(); ctx.arc(0, -120, 40, 0, Math.PI * 2); shape(ctx, hc); ctx.beginPath(); ctx.moveTo(-100, -30); ctx.bezierCurveTo(-96, -128, 96, -128, 100, -30); ctx.quadraticCurveTo(0, -80, -100, -30); shape(ctx, hc); }
  if (o.onHead) o.onHead(ctx);
  ctx.restore();
  if (arms.frontL) drawSuitArm(ctx, -66, arms.L, suit);
  if (arms.frontR) drawSuitArm(ctx, 66, arms.R, suit);
  if (o.handItem) { const h = arms.R[1]; ctx.save(); ctx.translate(h[0], h[1]); o.handItem(ctx); ctx.restore(); }
  if (o.after) o.after(ctx);
  ctx.restore();
}
function drawSuitArm(ctx, sx, arm, suit) {
  const [[ex, ey], [hx, hy]] = arm;
  line(ctx, [[sx, 140], [ex, ey], [hx, hy]], OW * 3.2, INK);
  line(ctx, [[sx, 140], [ex, ey], [hx, hy]], OW * 2.3, suit);
  ctx.beginPath(); ctx.arc(hx, hy, 17, 0, Math.PI * 2); shape(ctx, '#fff', INK, OW * 0.8);
}

/* ---------- natural motion ---------- */
/** Liveliness: blink, breathing bob, eye darts, small head drift. */
function alive(t, seed = 0) {
  const period = 3.1 + hash(seed) * 1.6, ph = (t + hash(seed + 9) * period) % period;
  const blink = ph < 0.14 ? Math.sin(ph / 0.14 * Math.PI) : 0;
  const dart = Math.floor((t + seed) / 1.7);
  return {
    blink,
    bob: Math.sin(t * 2.2 + seed) * 3,
    look: [noise(dart, seed) * 0.25, noise(dart, seed + 1) * 0.12],
    headRot: noise(t * 0.4, seed + 2) * 0.03,
  };
}
/** Walk cycle at `speed` steps per second. Returns {legs, bob, armSwing, dx}. */
function walk(t, speed = 1.6, stride = 34) {
  const p = t * speed * Math.PI;
  const a = Math.sin(p), b = Math.sin(p + Math.PI);
  const leg = (sx, k) => [[sx, 250], [sx + k * stride * 0.5, 300 - Math.max(0, k) * 10], [sx + k * stride, 352 - Math.max(0, k) * 14]];
  return {
    legs: { L: leg(-26, a), R: leg(26, b) },
    bob: -Math.abs(Math.sin(p)) * 10,
    arms: { L: [[-60 - b * 8, 196], [-62 - b * 22, 250]], R: [[60 - a * 8, 196], [62 - a * 22, 250]] },
  };
}
function run(t, speed = 3) {
  const w = walk(t, speed, 60);
  w.bob *= 2; w.lean = 0.08;
  w.arms = { L: [[-70, 170 + Math.sin(t * speed * Math.PI) * 20], [-40, 130]], R: [[70, 170 - Math.sin(t * speed * Math.PI) * 20], [40, 130]], fistL: true, fistR: true };
  return w;
}

/** Over-the-shoulder: big darkened back of head/shoulder at the frame edge (screen space). */
function ots(ctx, who = 'kiki', side = 'left', o = {}) {
  ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0);
  const x = side === 'left' ? 120 : W - 120;
  ctx.globalAlpha = o.alpha ?? 0.92;
  ctx.filter = `blur(${o.blur ?? 3}px) brightness(0.35)`;
  if (who === 'kiki') drawChar(ctx, kiki({ x, y: H - 300, s: 2.6, back: true, noLegs: true, flip: side === 'right' }));
  else if (who === 'suit') drawSuit(ctx, { x, y: H - 280, s: 2.4, back: true, noLegs: true, ...o.char });
  else drawChar(ctx, { x, y: H - 300, s: 2.6, back: true, noLegs: true, ...o.char });
  ctx.restore();
}

/* ---------- props (serious house style; no confetti/puffs/sweat gags) ---------- */
function phoneDraw(ctx, x, y, s = 1, o = {}) {
  ctx.save(); ctx.translate(x, y); ctx.scale(s, s); ctx.rotate(o.rot ?? 0);
  rr(ctx, -60, -110, 120, 220, 20); shape(ctx, '#1e2230');
  rr(ctx, -48, -94, 96, 180, 10); shape(ctx, o.screen || '#8fb7d9', null);
  if (o.glow) { ctx.globalAlpha = 0.35; ctx.fillStyle = o.screen || '#8fb7d9'; ctx.beginPath(); ctx.arc(0, 0, 200, 0, Math.PI * 2); ctx.fill(); ctx.globalAlpha = 1; }
  if (o.content) o.content(ctx);
  ctx.restore();
}
function wifiIcon(ctx, x, y, s = 1, col = '#fff', bars = 3) {
  ctx.save(); ctx.translate(x, y); ctx.scale(s, s);
  for (let i = 0; i < 3; i++) { ctx.beginPath(); ctx.arc(0, 0, 24 + i * 22, Math.PI * 1.25, Math.PI * 1.75); ctx.lineWidth = 10; ctx.lineCap = 'round'; ctx.strokeStyle = i < bars ? col : 'rgba(255,255,255,0.2)'; ctx.stroke(); }
  ctx.beginPath(); ctx.arc(0, 0, 7, 0, Math.PI * 2); ctx.fillStyle = col; ctx.fill();
  ctx.restore();
}
function coinDraw(ctx, x, y, s = 1) { ctx.save(); ctx.translate(x, y); ctx.scale(s, s); ctx.beginPath(); ctx.arc(0, 0, 40, 0, Math.PI * 2); shape(ctx, '#d4a537'); ctx.beginPath(); ctx.arc(0, 0, 26, 0, Math.PI * 2); shape(ctx, null, '#a87d22', 5); ctx.restore(); }
function billDraw(ctx, x, y, s = 1, rot = 0) { ctx.save(); ctx.translate(x, y); ctx.scale(s, s); ctx.rotate(rot); rr(ctx, -80, -40, 160, 80, 8); shape(ctx, '#7fa37a'); ctx.beginPath(); ctx.arc(0, 0, 22, 0, Math.PI * 2); shape(ctx, '#a9c7a4', INK, 4); ctx.restore(); }
function paperDraw(ctx, x, y, s = 1, o = {}) {
  ctx.save(); ctx.translate(x, y); ctx.scale(s, s); ctx.rotate(o.rot ?? 0);
  rr(ctx, -90, -120, 180, 240, 6); shape(ctx, '#f1ede2');
  ctx.globalAlpha = 0.45; for (let i = 0; i < 7; i++) line(ctx, [[-64, -80 + i * 26], [i === 6 ? 10 : 64, -80 + i * 26]], 5, '#6b6b6b'); ctx.globalAlpha = 1;
  if (o.mark) txt(ctx, o.mark, 40, 70, 80, o.markCol || '#b3262e');
  ctx.restore();
}
function doorDraw(ctx, x, y, s = 1, open = 0, col = '#5a4636') {
  ctx.save(); ctx.translate(x, y); ctx.scale(s, s);
  rr(ctx, -130, -300, 260, 600, 6); shape(ctx, '#121015');
  ctx.save(); ctx.transform(1 - open * 0.7, -open * 0.12, 0, 1, -130 * open * 0.7 * 0, 0);
  rr(ctx, -120, -290, 240, 580, 4); shape(ctx, col);
  ctx.beginPath(); ctx.arc(80, 10, 12, 0, Math.PI * 2); shape(ctx, '#c9a55a', INK, 4);
  ctx.restore(); ctx.restore();
}
function spinner(ctx, x, y, t, s = 1, col = '#fff') {
  ctx.save(); ctx.translate(x, y); ctx.scale(s, s);
  for (let i = 0; i < 8; i++) { ctx.globalAlpha = ((i + Math.floor(t * 10)) % 8) / 8; ctx.rotate(Math.PI / 4); rr(ctx, -5, -34, 10, 18, 5); ctx.fillStyle = col; ctx.fill(); }
  ctx.restore();
}
