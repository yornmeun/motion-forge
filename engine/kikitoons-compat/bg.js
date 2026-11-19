/*
 * MotionForge — Kikitoons-compatible engine: bg.js
 * Environments drawn in world coordinates (1080x1920), extended past the frame so the camera can
 * zoom/rotate. Each environment takes (ctx, t, o). Palettes are muted for the serious house tone.
 */
'use strict';

/** Depth layers with parallax. L = {bg, mid, fg} functions(ctx). Factors: bg ~0.4, mid 1, fg ~1.4. */
function layers(ctx, t, L, o = {}) {
  const c = currentCam();
  const f = { bg: o.bgF ?? 0.4, mid: 1, fg: o.fgF ?? 1.4 };
  const apply = k => cam(ctx, { z: 1 + (c.z - 1) * f[k], cx: W / 2 + (c.cx - W / 2) * f[k], cy: H / 2 + (c.cy - H / 2) * f[k], rot: c.rot * f[k], sx: c.sx * f[k], sy: c.sy * f[k] });
  for (const k of ['bg', 'mid', 'fg']) {
    if (!L[k]) continue;
    ctx.save(); apply(k);
    const blur = k === 'bg' ? (o.bgBlur ?? 0) : k === 'fg' ? (o.fgBlur ?? 4) : (o.midBlur ?? 0);
    if (blur > 0) ctx.filter = `blur(${blur}px)`;
    L[k](ctx, t);
    ctx.restore();
  }
  cam(ctx, c); // restore the main camera for anything drawn afterwards
}
/** Rack focus helper: returns [bgBlur, fgBlur] moving focus from fg (k=0) to bg (k=1). */
function rackFocus(k) { k = clamp(k); return { bgBlur: lerp(6, 0, k), fgBlur: lerp(0, 8, k), midBlur: 0 }; }

function wallGrad(ctx, top, bottom, y0 = -400, y1 = 1400) {
  const g = ctx.createLinearGradient(0, y0, 0, y1); g.addColorStop(0, top); g.addColorStop(1, bottom);
  ctx.fillStyle = g; ctx.fillRect(-W, -H, W * 3, H * 3);
}

function hallway(ctx, t = 0, o = {}) {
  wallGrad(ctx, o.wall || '#4a5563', '#2c333d');
  // vanishing-point corridor
  const vx = 540, vy = 820;
  ctx.fillStyle = '#3a4250'; ctx.beginPath(); ctx.moveTo(-W, 1350); ctx.lineTo(vx - 120, vy + 80); ctx.lineTo(vx + 120, vy + 80); ctx.lineTo(W * 2, 1350); ctx.lineTo(W * 2, H * 2); ctx.lineTo(-W, H * 2); ctx.fill();
  // floor tiles
  ctx.strokeStyle = 'rgba(0,0,0,0.25)'; ctx.lineWidth = 3;
  for (let i = -8; i <= 8; i++) { ctx.beginPath(); ctx.moveTo(vx + i * 15, vy + 80); ctx.lineTo(vx + i * 260, 2200); ctx.stroke(); }
  // lockers both sides
  for (let i = 0; i < 7; i++) {
    const k = i / 7, x0 = lerp(-200, vx - 140, k), h = lerp(900, 160, k), w = lerp(150, 26, k);
    for (const side of [-1, 1]) {
      const x = side < 0 ? x0 : W - x0 - w;
      rr(ctx, x, vy + 80 - h * 0.9, w, h, 4); shape(ctx, side < 0 ? '#5f6b5e' : '#5b6370', 'rgba(0,0,0,0.5)', 3);
      ctx.fillStyle = 'rgba(0,0,0,0.35)'; ctx.fillRect(x + w * 0.2, vy + 80 - h * 0.8, w * 0.6, 4);
    }
  }
  // ceiling lights (practicals, flicker)
  for (let i = 0; i < 5; i++) {
    const k = i / 5, y = lerp(-80, vy - 60, k), w = lerp(320, 40, k);
    const fl = o.flicker ? flicker(t, i) : 1;
    ctx.globalAlpha = 0.85 * fl; ctx.fillStyle = '#e8e2c8'; ctx.fillRect(vx - w / 2, y, w, lerp(24, 4, k)); ctx.globalAlpha = 1;
  }
  // end door
  rr(ctx, vx - 60, vy - 70, 120, 150, 3); shape(ctx, '#2a2420', 'rgba(0,0,0,0.6)', 3);
}

function classroomWall(ctx, t = 0, o = {}) {
  wallGrad(ctx, o.wall || '#5c6a62', '#3c4640');
  rr(ctx, 140, 260, 800, 440, 10); shape(ctx, '#27352e', '#1b1f1c', 10); // chalkboard
  ctx.globalAlpha = 0.25; for (let i = 0; i < 4; i++) line(ctx, [[200, 340 + i * 80], [lerp(600, 860, hash(i)), 340 + i * 80]], 6, '#e8e8e0'); ctx.globalAlpha = 1;
  // clock
  ctx.beginPath(); ctx.arc(900, 160, 60, 0, Math.PI * 2); shape(ctx, '#ebe6da');
  const a = (o.clockT ?? t) * 0.5; line(ctx, [[900, 160], [900 + Math.sin(a) * 44, 160 - Math.cos(a) * 44]], 5); line(ctx, [[900, 160], [900 + Math.sin(a / 12) * 30, 160 - Math.cos(a / 12) * 30]], 7);
  // posters
  rr(ctx, 60, 780, 160, 210, 6); shape(ctx, '#8a6f55'); rr(ctx, 860, 800, 160, 190, 6); shape(ctx, '#58708a');
  ctx.fillStyle = '#2f2a26'; ctx.fillRect(-W, 1180, W * 3, 30);
}
function floorWood(ctx, t = 0, o = {}) {
  ctx.fillStyle = o.col || '#5a4434'; ctx.fillRect(-W, o.y ?? 1200, W * 3, H * 2);
  ctx.strokeStyle = 'rgba(0,0,0,0.25)'; ctx.lineWidth = 4;
  for (let y = (o.y ?? 1200) + 60; y < H * 2; y += 90) { ctx.beginPath(); ctx.moveTo(-W, y); ctx.lineTo(W * 2, y); ctx.stroke(); }
}
function desk(ctx, x, y, s = 1, col = '#6d5644') {
  ctx.save(); ctx.translate(x, y); ctx.scale(s, s);
  rr(ctx, -260, -30, 520, 60, 8); shape(ctx, col);
  for (const sx of [-220, 200]) { rr(ctx, sx, 30, 22, 300, 4); shape(ctx, shade(col, -0.3)); }
  ctx.restore();
}

function officeBg(ctx, t = 0, o = {}) {
  wallGrad(ctx, o.wall || '#4d4a52', '#2b2830');
  // window with blinds (light shafts)
  rr(ctx, 560, 180, 420, 560, 6); shape(ctx, '#7f93a8', '#1b1a1e', 12);
  ctx.fillStyle = 'rgba(20,20,26,0.55)'; for (let i = 0; i < 14; i++) ctx.fillRect(566, 196 + i * 40, 408, 16);
  // shelves + diplomas
  rr(ctx, 80, 300, 360, 18, 3); shape(ctx, '#3b2f28'); rr(ctx, 80, 520, 360, 18, 3); shape(ctx, '#3b2f28');
  for (let i = 0; i < 6; i++) { rr(ctx, 100 + i * 54, 200 + (i % 2) * 10, 40, 100 - (i % 2) * 10, 3); shape(ctx, ['#6b3a3a', '#3a4b6b', '#5b5b3a'][i % 3]); }
  rr(ctx, 160, 380, 120, 110, 3); shape(ctx, '#d8cfb8', '#5a4a30', 8);
  ctx.fillStyle = '#231f24'; ctx.fillRect(-W, 1200, W * 3, H * 2);
  desk(ctx, 540, 1180, 1.3, '#4b3a30');
}

function serverRoom(ctx, t = 0, o = {}) {
  wallGrad(ctx, '#1c2330', '#0d1118');
  for (let i = 0; i < 6; i++) {
    const x = -60 + i * 210;
    rr(ctx, x, 260, 170, 1000, 6); shape(ctx, '#232b38', '#0a0d12', 6);
    for (let j = 0; j < 18; j++) {
      const on = hash(i * 31 + j + Math.floor(t * 4 + hash(j) * 3)) > 0.4;
      ctx.fillStyle = on ? (j % 5 === 0 ? '#c8463b' : '#4fc28a') : '#1a1f28';
      ctx.fillRect(x + 20, 300 + j * 52, 10, 8); ctx.fillRect(x + 40, 300 + j * 52, 90, 3);
    }
  }
  ctx.fillStyle = '#0f131a'; ctx.fillRect(-W, 1260, W * 3, H * 2);
}

function street(ctx, t = 0, o = {}) {
  const night = !!o.night;
  wallGrad(ctx, night ? '#141a2a' : '#7a8794', night ? '#252a36' : '#a3a8a6', -400, 1000);
  for (let i = 0; i < 6; i++) {
    const x = -120 + i * 230, h = 500 + hash(i) * 500;
    ctx.fillStyle = night ? '#1d2230' : '#5d6670'; ctx.fillRect(x, 1100 - h, 200, h + 100);
    for (let r = 0; r < h / 90 - 1; r++) for (let c = 0; c < 3; c++) {
      const lit = night ? hash(i * 13 + r * 7 + c) > 0.6 : false;
      ctx.fillStyle = lit ? '#d9b46a' : (night ? '#2a3040' : '#8a96a0'); ctx.fillRect(x + 22 + c * 60, 1100 - h + 40 + r * 90, 36, 50);
    }
  }
  ctx.fillStyle = '#3a3d42'; ctx.fillRect(-W, 1150, W * 3, H * 2);
  ctx.fillStyle = '#5a5d60'; ctx.fillRect(-W, 1150, W * 3, 40);
  ctx.strokeStyle = 'rgba(230,220,180,0.5)'; ctx.lineWidth = 10; ctx.setLineDash([80, 60]);
  ctx.beginPath(); ctx.moveTo(-W, 1500); ctx.lineTo(W * 2, 1500); ctx.stroke(); ctx.setLineDash([]);
  if (night) { // street lamp
    rr(ctx, 860, 520, 16, 640, 4); shape(ctx, '#202228'); ctx.globalAlpha = 0.25 * flicker(t, 7); ctx.fillStyle = '#e8c27a';
    ctx.beginPath(); ctx.moveTo(868, 520); ctx.lineTo(700, 1180); ctx.lineTo(1040, 1180); ctx.closePath(); ctx.fill(); ctx.globalAlpha = 1;
  }
}

function kitchen(ctx, t = 0, o = {}) {
  wallGrad(ctx, o.wall || '#6a6258', '#45403a');
  ctx.fillStyle = '#d8d2c4'; for (let y = 700; y < 1000; y += 60) for (let x = -40; x < 1120; x += 60) { ctx.fillRect(x + 2, y + 2, 56, 56); }
  rr(ctx, -40, 1000, 1160, 60, 4); shape(ctx, '#3d3530'); rr(ctx, -40, 1060, 1160, 400, 0); shape(ctx, '#5b4a3e');
  for (let i = 0; i < 4; i++) { rr(ctx, 20 + i * 270, 1100, 240, 300, 6); shape(ctx, '#6a5748', 'rgba(0,0,0,0.5)', 4); }
  for (let i = 0; i < 3; i++) { rr(ctx, 60 + i * 340, 220, 300, 360, 6); shape(ctx, '#5e5046', 'rgba(0,0,0,0.5)', 4); }
  rr(ctx, 380, 620, 320, 70, 30); shape(ctx, '#9aa3a8'); // hood light
  ctx.fillStyle = '#2b2724'; ctx.fillRect(-W, 1460, W * 3, H * 2);
}

function bedroom(ctx, t = 0, o = {}) {
  const night = !!o.night;
  wallGrad(ctx, night ? '#1f2433' : '#5e6a78', night ? '#141823' : '#3e4652');
  rr(ctx, 620, 240, 340, 420, 8); shape(ctx, night ? '#2a3858' : '#9bb3c8', '#1b1a1e', 12);
  if (night) { ctx.beginPath(); ctx.arc(860, 340, 40, 0, Math.PI * 2); ctx.fillStyle = '#d9dbe0'; ctx.fill(); }
  line(ctx, [[790, 240], [790, 660]], 10, '#1b1a1e'); line(ctx, [[620, 450], [960, 450]], 10, '#1b1a1e');
  rr(ctx, 60, 360, 220, 280, 6); shape(ctx, night ? '#3a3040' : '#7a6a5a'); // poster
  ctx.fillStyle = night ? '#1a1820' : '#4a3f38'; ctx.fillRect(-W, 1180, W * 3, H * 2);
  rr(ctx, -80, 1020, 700, 220, 20); shape(ctx, night ? '#2e3a5a' : '#566a8a'); // bed
  rr(ctx, -60, 980, 220, 90, 30); shape(ctx, '#e6e2d8');
  if (night) { // bedside lamp glow
    ctx.globalAlpha = 0.25 * flicker(t, 3); const g = ctx.createRadialGradient(760, 1000, 10, 760, 1000, 420); g.addColorStop(0, '#e8b46a'); g.addColorStop(1, 'rgba(232,180,106,0)'); ctx.fillStyle = g; ctx.fillRect(300, 560, 920, 900); ctx.globalAlpha = 1;
    rr(ctx, 730, 960, 60, 80, 8); shape(ctx, '#d8a860');
  }
}

function park(ctx, t = 0, o = {}) {
  wallGrad(ctx, o.sky || '#8fa0ad', '#c2c3b4', -400, 1100);
  for (let i = 0; i < 7; i++) { ctx.fillStyle = shade('#55684f', -hash(i) * 0.3); ctx.beginPath(); ctx.ellipse(-100 + i * 200, 980 - hash(i + 3) * 120, 170, 200, 0, 0, Math.PI * 2); ctx.fill(); }
  ctx.fillStyle = '#6b7b56'; ctx.fillRect(-W, 1080, W * 3, H * 2);
  ctx.fillStyle = '#8f8573'; ctx.beginPath(); ctx.moveTo(380, 1080); ctx.lineTo(700, 1080); ctx.lineTo(1300, 2400); ctx.lineTo(-200, 2400); ctx.fill();
  rr(ctx, 60, 1180, 300, 30, 6); shape(ctx, '#4a3a2e'); for (const x of [80, 320]) { rr(ctx, x, 1210, 16, 80, 3); shape(ctx, '#2e2620'); }
}

function shop(ctx, t = 0, o = {}) {
  wallGrad(ctx, '#5a5048', '#3a332e');
  for (let r = 0; r < 4; r++) { rr(ctx, 60, 260 + r * 200, 960, 16, 3); shape(ctx, '#2e2520'); for (let i = 0; i < 9; i++) { rr(ctx, 80 + i * 104, 170 + r * 200, 80, 90, 6); shape(ctx, ['#8a5a4a', '#5a7a6a', '#7a7a4a', '#4a5a7a'][(i + r) % 4], 'rgba(0,0,0,0.4)', 4); } }
  rr(ctx, -40, 1080, 1160, 380, 6); shape(ctx, '#6a4e3a'); // counter
  ctx.fillStyle = '#231e1b'; ctx.fillRect(-W, 1460, W * 3, H * 2);
}

/* ---------- atmosphere ---------- */
function bokeh(ctx, t = 0, n = 14, col = '#e8c27a') {
  ctx.save(); ctx.globalCompositeOperation = 'lighter';
  for (let i = 0; i < n; i++) { const x = hash(i) * W, y = hash(i + 50) * H * 0.8 + noise(t * 0.2, i) * 30, r = 30 + hash(i + 9) * 70; ctx.globalAlpha = 0.06 + hash(i + 3) * 0.08; ctx.fillStyle = col; ctx.beginPath(); ctx.arc(x, y, r, 0, Math.PI * 2); ctx.fill(); }
  ctx.restore();
}
/** Soft light shafts from a point (serious — no cartoon rays). */
function rays(ctx, t = 0, x = 900, y = 100, col = 'rgba(240,220,170,0.10)', n = 4) {
  ctx.save(); ctx.globalCompositeOperation = 'lighter';
  for (let i = 0; i < n; i++) { const a = 1.9 + i * 0.12 + noise(t * 0.1, i) * 0.02; ctx.fillStyle = col; ctx.beginPath(); ctx.moveTo(x, y); ctx.lineTo(x + Math.cos(a) * 2600, y + Math.sin(a) * 2600); ctx.lineTo(x + Math.cos(a + 0.06) * 2600, y + Math.sin(a + 0.06) * 2600); ctx.closePath(); ctx.fill(); }
  ctx.restore();
}
function plant(ctx, x, y, s = 1, silhouette = null) {
  ctx.save(); ctx.translate(x, y); ctx.scale(s, s);
  rr(ctx, -60, 0, 120, 130, 10); shape(ctx, silhouette || '#6b4a3a', silhouette ? null : INK);
  for (let i = 0; i < 7; i++) { ctx.save(); ctx.rotate(-1.2 + i * 0.4); ctx.beginPath(); ctx.ellipse(0, -110, 30, 110, 0, 0, Math.PI * 2); shape(ctx, silhouette || '#3f5a3c', silhouette ? null : INK); ctx.restore(); }
  ctx.restore();
}
/** Dark foreground silhouettes: 'doorframe' | 'plant' | 'desk' | 'locker' | 'shoulder'. */
function fgSilhouette(ctx, kind = 'doorframe', side = 'left') {
  // screen space: x from the frame edges, y scaled from the 1920-tall reference frame
  ctx.save(); ctx.fillStyle = '#07070a';
  const L = side === 'left', y = v => v * SH / 1920;
  switch (kind) {
    case 'doorframe': ctx.fillRect(L ? -400 : SW - 80, -SH, 480, SH * 3); break;
    case 'plant': plant(ctx, L ? -10 : SW + 10, y(1700), 1.9 * Math.max(0.6, SH / 1920), '#07070a'); break;
    case 'desk': ctx.fillRect(-SW, y(1640), SW * 3, 600); break;
    case 'locker': ctx.fillRect(L ? -300 : SW - 120, -SH, 420, SH * 3); break;
    case 'shoulder': ctx.beginPath(); ctx.ellipse(L ? 60 : SW - 60, y(1900), 420, 520, 0, 0, Math.PI * 2); ctx.fill(); break;
  }
  ctx.restore();
}
function flicker(t, seed = 0) {
  const n = noise(t * 9, seed + 11);
  return n < -0.82 ? 0.35 : 0.92 + noise(t * 3, seed) * 0.08;
}
function rain(ctx, t, amt = 1) {
  ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.strokeStyle = 'rgba(200,210,230,0.28)'; ctx.lineWidth = 2;
  for (let i = 0; i < 160 * amt; i++) { const x = (hash(i) * SW * 1.2 + t * 220) % (SW * 1.2) - 60, y = (hash(i + 99) * SH + t * 2400) % SH; ctx.beginPath(); ctx.moveTo(x, y); ctx.lineTo(x - 12, y + 60); ctx.stroke(); }
  ctx.restore();
}
function dust(ctx, t, amt = 1, beam = null) {
  ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.fillStyle = 'rgba(240,230,200,0.5)';
  for (let i = 0; i < 70 * amt; i++) {
    const x = hash(i) * SW + noise(t * 0.3, i) * 60, y = (hash(i + 7) * SH + t * (8 + hash(i) * 14)) % SH;
    if (beam && (x < beam[0] || x > beam[1])) continue;
    ctx.globalAlpha = 0.15 + hash(i + 4) * 0.35; ctx.beginPath(); ctx.arc(x, y, 1.5 + hash(i + 2) * 2.5, 0, Math.PI * 2); ctx.fill();
  }
  ctx.restore();
}
function fog(ctx, t, amt = 0.3, col = '200,205,215') {
  ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0);
  for (let i = 0; i < 4; i++) { const g = ctx.createRadialGradient(SW * hash(i) + noise(t * 0.1, i) * 200, SH * (0.5 + 0.3 * hash(i + 1)), 50, SW * hash(i), SH * 0.6, 900); g.addColorStop(0, `rgba(${col},${amt * 0.5})`); g.addColorStop(1, `rgba(${col},0)`); ctx.fillStyle = g; ctx.fillRect(0, 0, SW, SH); }
  ctx.restore();
}

/** Registry used by the template director and the editor's background picker. */
const BACKGROUNDS = { hallway, classroom: (c, t, o) => { classroomWall(c, t, o); floorWood(c, t, o); }, office: officeBg, serverRoom, street, streetNight: (c, t, o) => street(c, t, { ...o, night: true }), kitchen, bedroom, bedroomNight: (c, t, o) => bedroom(c, t, { ...o, night: true }), park, shop };
