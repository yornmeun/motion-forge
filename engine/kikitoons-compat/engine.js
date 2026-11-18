/*
 * MotionForge — Kikitoons-compatible engine: engine.js
 * Captions, transitions, grading and frame assembly. Do not edit per story.
 *
 * Page-load contract: core.js, bg.js, <story file>, engine.js. The story file defines `STORY`:
 *   { scenes:[fn(ctx,t)], starts:[...n+1], transitions:[...n-1], narr:[[scene,start,end,text]],
 *     overlay(ctx,i,lt,T)?, captionY?, captionTheme?: 'serious', looks?:[preset per scene] }
 * Optional global CAPS (from vo/SRT alignment): [{start,end,text,words:[{w,start,end}]}] in global seconds.
 */
'use strict';

(function () {
  const S = (typeof STORY !== 'undefined') ? STORY : null;
  const canvas = document.getElementById('stage');
  const ctx = canvas.getContext('2d');
  const offA = document.createElement('canvas'), offB = document.createElement('canvas');
  offA.width = offB.width = W; offA.height = offB.height = H;
  const cA = offA.getContext('2d'), cB = offB.getContext('2d');
  const errors = [];

  const LOOKS = {
    neutral: { desat: 0.82, contrast: 1.06, tint: null, vig: 0.55 },
    warm: { desat: 0.85, contrast: 1.05, tint: 'rgba(200,140,70,0.10)', vig: 0.5 },
    cold: { desat: 0.7, contrast: 1.08, tint: 'rgba(60,100,160,0.14)', vig: 0.65 },
    night: { desat: 0.6, contrast: 1.1, tint: 'rgba(20,30,80,0.28)', vig: 0.75 },
    tense: { desat: 0.62, contrast: 1.14, tint: 'rgba(120,30,30,0.10)', vig: 0.78 },
    payoff: { desat: 0.88, contrast: 1.08, tint: 'rgba(210,150,60,0.12)', vig: 0.5 },
    clean: { desat: 1, contrast: 1, tint: null, vig: 0 },
  };

  const n = S ? S.scenes.length : 0;
  const duration = S ? S.starts[n] : 0;
  const sceneAt = T => { for (let i = n - 1; i >= 0; i--) if (T >= S.starts[i]) return i; return 0; };

  function drawScene(c, i, lt) {
    c.save(); c.setTransform(1, 0, 0, 1, 0, 0); c.clearRect(0, 0, W, H); c.fillStyle = '#000'; c.fillRect(0, 0, W, H);
    try {
      cam(c, {});
      S.scenes[i](c, Math.max(0, lt));
    } catch (e) {
      if (!errors.find(x => x.scene === i)) errors.push({ scene: i, message: String(e && e.message || e), t: lt });
    }
    c.restore();
  }

  function compose(src, look) {
    const L = LOOKS[look] || LOOKS.neutral;
    ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.filter = `saturate(${L.desat}) contrast(${L.contrast})`;
    ctx.drawImage(src, 0, 0);
    ctx.filter = 'none';
    if (L.tint) { ctx.globalCompositeOperation = 'soft-light'; ctx.fillStyle = L.tint; ctx.fillRect(0, 0, W, H); ctx.globalCompositeOperation = 'source-over'; ctx.fillStyle = L.tint; ctx.globalAlpha = 0.5; ctx.fillRect(0, 0, W, H); ctx.globalAlpha = 1; }
    ctx.restore();
    return L;
  }

  function vignette(amt) {
    if (amt <= 0) return;
    const g = ctx.createRadialGradient(W / 2, H * 0.46, H * 0.25, W / 2, H / 2, H * 0.72);
    g.addColorStop(0, 'rgba(0,0,0,0)'); g.addColorStop(1, `rgba(0,0,0,${amt})`);
    ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.fillStyle = g; ctx.fillRect(0, 0, W, H); ctx.restore();
  }
  // static grain tile, offset per frame
  const grainTile = document.createElement('canvas'); grainTile.width = grainTile.height = 256;
  (function () { const g = grainTile.getContext('2d'), d = g.createImageData(256, 256); for (let i = 0; i < d.data.length; i += 4) { const v = Math.random() * 255; d.data[i] = d.data[i + 1] = d.data[i + 2] = v; d.data[i + 3] = 255; } g.putImageData(d, 0, 0); })();
  function grain(T, amt = 0.06) {
    ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.globalAlpha = amt; ctx.globalCompositeOperation = 'overlay';
    const ox = Math.floor(hash(Math.floor(T * 30)) * 256), oy = Math.floor(hash(Math.floor(T * 30) + 7) * 256);
    ctx.translate(-ox, -oy); ctx.fillStyle = ctx.createPattern(grainTile, 'repeat'); ctx.fillRect(0, 0, W + 256, H + 256);
    ctx.restore();
  }

  /* ---------- transitions ---------- */
  const TD = { whip: 0.32, zoom: 0.4, dip: 0.7, smash: 0.12, match: 0.45, slide: 0.4, none: 0 };
  function transitionState(T) {
    for (let b = 1; b < n; b++) {
      const kind = (S.transitions && S.transitions[b - 1]) || 'none', d = TD[kind] ?? 0, tb = S.starts[b];
      if (d > 0 && T >= tb - d / 2 && T < tb + d / 2) return { b, kind, k: (T - (tb - d / 2)) / d };
    }
    return null;
  }

  /* ---------- captions ---------- */
  function parseWords(text) {
    return text.split(/\s+/).filter(Boolean).map(raw => {
      let w = raw, kind = 'plain';
      if (/^!/.test(w)) { kind = 'slam'; w = w.slice(1); }
      const m = w.match(/^\*(.+)\*([.,!?…]*)$/); if (m) { kind = 'key'; w = m[1] + m[2]; }
      return { w, kind };
    });
  }
  function captionCues() {
    if (typeof CAPS !== 'undefined' && Array.isArray(CAPS)) return CAPS;
    if (!S || !S.narr) return [];
    return S.narr.map(([i, a, b, text]) => {
      const st = S.starts[i] + a, en = S.starts[i] + b, ws = parseWords(text), d = (en - st) / Math.max(1, ws.length);
      return { start: st, end: en, text, words: ws.map((x, k) => ({ w: x.w, kind: x.kind, start: st + k * d, end: st + (k + 1) * d })) };
    });
  }
  const CUES = captionCues().map(c => ({ ...c, words: (c.words || []).map(x => x.kind ? x : { ...x, ...(parseWords(x.w)[0] || { w: x.w, kind: 'plain' }) }) }));
  const CAP_Y = Math.min(1450, S && S.captionY ? S.captionY : 1344);

  function drawCaptions(T) {
    const cue = CUES.find(c => T >= c.start && T < c.end + 0.15);
    if (!cue) return;
    ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0);
    const size = 70, keySize = 80, maxW = 900, gap = 18;
    // lay the cue out in lines, then group lines into pages of ≤2 and show the page holding the
    // most recently spoken word, so no spoken word is ever skipped and never more than 2 lines show
    const measured = cue.words.filter(w => w.kind !== 'slam').map(w => { ctx.font = `700 ${w.kind === 'key' ? keySize : size}px Poppins, sans-serif`; return { ...w, wd: ctx.measureText(w.w.toUpperCase()).width }; });
    const lines = [[]]; let lw = 0;
    for (const m of measured) { if (lw + m.wd > maxW && lines[lines.length - 1].length) { lines.push([]); lw = 0; } lines[lines.length - 1].push(m); lw += m.wd + gap; }
    const pages = []; for (let p = 0; p < lines.length; p += 2) pages.push(lines.slice(p, p + 2));
    let page = 0; pages.forEach((pg, pi) => { if (pg[0] && pg[0][0] && T >= pg[0][0].start) page = pi; });
    const shown = pages[page] || [], lh = 92, y0 = CAP_Y - (shown.length - 1) * lh / 2;
    shown.forEach((ln, li) => {
      const total = ln.reduce((a, m) => a + m.wd + gap, -gap); let x = W / 2 - total / 2;
      for (const m of ln) {
        const k = clamp((T - m.start) / 0.22); if (k <= 0) { x += m.wd + gap; continue; }
        const e = E.o(k), sz = m.kind === 'key' ? keySize : size;
        ctx.globalAlpha = e; ctx.font = `700 ${sz}px Poppins, sans-serif`; ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
        const y = y0 + li * lh + (1 - e) * 14;
        ctx.lineWidth = 14; ctx.strokeStyle = 'rgba(10,8,12,0.92)'; ctx.lineJoin = 'round'; ctx.strokeText(m.w.toUpperCase(), x, y);
        ctx.fillStyle = m.kind === 'key' ? '#e0a63a' : '#f3efe6'; ctx.fillText(m.w.toUpperCase(), x, y);
        x += m.wd + gap;
      }
    });
    // slam word: giant, deep red, slow scale-in with a short shake
    const slam = cue.words.find(w => w.kind === 'slam' && T >= w.start);
    if (slam) {
      const k = clamp((T - slam.start) / 0.6), e = E.heavy(k), sh = shk(T, slam.start, 14, 0.4);
      ctx.globalAlpha = Math.min(1, k * 3); ctx.textAlign = 'center';
      ctx.translate(W / 2 + sh.sx, CAP_Y - 230 + sh.sy); ctx.scale(lerp(1.35, 1, e), lerp(1.35, 1, e));
      ctx.font = `700 190px Poppins, sans-serif`; ctx.lineWidth = 22; ctx.strokeStyle = '#0b0709'; ctx.strokeText(slam.w.toUpperCase(), 0, 0);
      ctx.fillStyle = '#b3201f'; ctx.fillText(slam.w.toUpperCase(), 0, 0);
    }
    ctx.restore();
  }

  /* ---------- frame ---------- */
  function frame(T) {
    T = clamp(T, 0, Math.max(0, duration - 1e-4));
    const tr = transitionState(T);
    let look;
    if (!tr) {
      const i = sceneAt(T); drawScene(cA, i, T - S.starts[i]);
      look = compose(offA, (S.looks && S.looks[i]) || 'neutral');
    } else {
      const a = tr.b - 1, b = tr.b, k = tr.k;
      drawScene(cA, a, T - S.starts[a]); drawScene(cB, b, T - S.starts[b]);
      const la = (S.looks && S.looks[a]) || 'neutral', lb = (S.looks && S.looks[b]) || 'neutral';
      ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.fillStyle = '#000'; ctx.fillRect(0, 0, W, H); ctx.restore();
      switch (tr.kind) {
        case 'whip': {
          const e = E.io(k), src = e < 0.5 ? offA : offB, dir = e < 0.5 ? -e * 2 : (1 - e) * 2;
          ctx.save(); ctx.filter = `blur(${Math.sin(e * Math.PI) * 26}px)`; ctx.drawImage(src, dir * W * 0.6 * (e < 0.5 ? 1 : -1), 0); ctx.restore();
          look = LOOKS[e < 0.5 ? la : lb] || LOOKS.neutral; break;
        }
        case 'zoom': {
          const e = E.io(k); ctx.save(); ctx.globalAlpha = 1 - e; ctx.translate(W / 2, H / 2); ctx.scale(1 + e * 0.6, 1 + e * 0.6); ctx.drawImage(offA, -W / 2, -H / 2); ctx.restore();
          ctx.save(); ctx.globalAlpha = e; ctx.translate(W / 2, H / 2); ctx.scale(0.7 + e * 0.3, 0.7 + e * 0.3); ctx.drawImage(offB, -W / 2, -H / 2); ctx.restore();
          look = LOOKS[e < 0.5 ? la : lb] || LOOKS.neutral; break;
        }
        case 'dip': {
          if (k < 0.5) { compose(offA, la); ctx.fillStyle = `rgba(0,0,0,${E.io(k * 2)})`; ctx.fillRect(0, 0, W, H); look = LOOKS[la]; }
          else { compose(offB, lb); ctx.fillStyle = `rgba(0,0,0,${1 - E.io((k - 0.5) * 2)})`; ctx.fillRect(0, 0, W, H); look = LOOKS[lb]; }
          look = look || LOOKS.neutral; break;
        }
        case 'smash': { compose(k < 0.5 ? offA : offB, k < 0.5 ? la : lb); ctx.fillStyle = `rgba(255,255,255,${k < 0.5 ? 0 : 0.85 * (1 - (k - 0.5) * 2)})`; ctx.fillRect(0, 0, W, H); look = LOOKS[lb] || LOOKS.neutral; break; }
        case 'slide': { const e = E.heavy(k); ctx.drawImage(offA, -e * W, 0); ctx.drawImage(offB, W - e * W, 0); look = LOOKS[e < 0.5 ? la : lb] || LOOKS.neutral; break; }
        case 'match': default: { compose(offA, la); ctx.save(); ctx.globalAlpha = E.io(k); ctx.drawImage(offB, 0, 0); ctx.restore(); look = LOOKS[lb] || LOOKS.neutral; }
      }
    }
    const i = sceneAt(T);
    if (S.overlay) { try { ctx.save(); S.overlay(ctx, i, T - S.starts[i], T); ctx.restore(); } catch (e) { errors.push({ scene: i, message: 'overlay: ' + e.message, t: T }); } }
    vignette((look || LOOKS.neutral).vig);
    if ((S.look || 'cinematic') !== 'clean') grain(T, S.grain ?? 0.07);
    if (!S.noCaptions) drawCaptions(T);
  }

  window.MF = {
    W, H, fps: S && S.fps || 30, duration, scenes: n, errors, cues: CUES,
    starts: S ? S.starts.slice() : [],
    frame,
    jpeg(T, q = 0.92) { frame(T); return canvas.toDataURL('image/jpeg', q); },
    png(T) { frame(T); return canvas.toDataURL('image/png'); },
  };
})();

/** End card helper (house tone): advice line + "FOLLOW FOR MORE VIDEOS". Use inside a scene. */
function endCard(ctx, t, o = {}) {
  const dur = o.dur ?? 4.5; t = Math.min(t, dur - 1);
  const c = camKeys(t, [[0, { z: 1.0, cy: 960 }], [dur, { z: 1.08, cy: 930 }]]);
  cam(ctx, camMix(c, handheld(t, 3, 4)));
  (BACKGROUNDS[o.bg || 'bedroomNight'] || BACKGROUNDS.hallway)(ctx, t, {});
  const al = alive(t, 3);
  drawChar(ctx, kiki({ x: 540, y: 1010 + al.bob, s: 1.25, headRot: al.headRot + Math.sin(seg(t, 1.2, 2.0) * Math.PI) * 0.06, face: { brows: 'flat', look: [0, 0.05], blink: al.blink, mouth: 'smirk', lid: 0.15 }, arms: ARMS.rest }));
  ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0);
  ctx.fillStyle = 'rgba(5,4,8,0.55)'; ctx.fillRect(0, 0, W, 560);
  const a = E.o(seg(t, 0.3, 1.2));
  const words = String(o.advice || '').split(' '), accent = (o.accent || '').toLowerCase();
  ctx.font = '700 84px Poppins, sans-serif';
  // simple 2–3 line wrap with accent colouring
  const lines = [[]]; let lw = 0;
  for (const w of words) { const wd = ctx.measureText(w + ' ').width; if (lw + wd > 900 && lines[lines.length - 1].length) { lines.push([]); lw = 0; } lines[lines.length - 1].push(w); lw += wd; }
  lines.forEach((ln, li) => {
    const total = ctx.measureText(ln.join(' ')).width; let x = W / 2 - total / 2; const y = 250 + li * 100 + (1 - a) * 20 - (lines.length - 1) * 50;
    for (const w of ln) { ctx.globalAlpha = a; ctx.textAlign = 'left'; ctx.textBaseline = 'middle'; ctx.lineWidth = 12; ctx.strokeStyle = '#0b0709'; ctx.strokeText(w, x, y); ctx.fillStyle = w.toLowerCase().replace(/[^a-z']/g, '') === accent ? '#e0a63a' : '#f3efe6'; ctx.fillText(w, x, y); x += ctx.measureText(w + ' ').width; }
  });
  const f = E.heavy(seg(t, o.followAt ?? 2.2, (o.followAt ?? 2.2) + 0.8));
  ctx.globalAlpha = f; ctx.textAlign = 'center'; ctx.font = '700 110px Poppins, sans-serif';
  const y2 = 1540; ctx.lineWidth = 20; ctx.strokeStyle = '#0b0709';
  ctx.strokeText('FOLLOW', W / 2, y2); ctx.fillStyle = '#b3201f'; ctx.fillText('FOLLOW', W / 2, y2);
  ctx.font = '700 92px Poppins, sans-serif'; ctx.strokeText('FOR MORE VIDEOS', W / 2, y2 + 110); ctx.fillStyle = '#ffffff'; ctx.fillText('FOR MORE VIDEOS', W / 2, y2 + 110);
  ctx.restore();
}
