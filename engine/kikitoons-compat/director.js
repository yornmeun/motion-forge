/*
 * MotionForge template director runtime (story-level helper, injected at the top of story files).
 * mfStage(ctx, t, P) stages one planned scene with the documented engine API only:
 *   P = { dur, bg, look, shot, move, emotion, actions[], beats?:[{a, w}], otherAct?, si?, cast:[{kind, def, name}],
 *         hero?, seed, kw, slamAt }
 * beats are action keys (see MF_ACTS) with the narration word that triggers each one: the character starts the
 * action as that word is spoken, so the picture acts out what the voiceover says. Plans without beats fall back
 * to their action phrases (P.actions), and with no actions at all the character still acts out the emotion.
 * Used by the "template" generation strategy; AI-generated scenes are written from scratch instead.
 */
'use strict';

const MF_FACES = {
  neutral: { brows: 'flat', mouth: 'flat' },
  determined: { brows: 'stern', mouth: 'flat', lid: 0.18 },
  worried: { brows: 'worried', mouth: 'wavy' },
  shocked: { brows: 'up', mouth: 'O', ma: 0.6, browLift: 0.6 },
  sad: { brows: 'worried', mouth: 'sad', lid: 0.35 },
  angry: { brows: 'angry', mouth: 'teeth' },
  sneaky: { brows: 'sneaky', mouth: 'smirk', lid: 0.38 },
  confident: { brows: 'flat', mouth: 'smirk', lid: 0.18 },
  relieved: { brows: 'up', mouth: 'happy', lid: 0.25 },
  afraid: { brows: 'worried', mouth: 'O', ma: 0.3, browLift: 0.4 },
  stern: { brows: 'stern', mouth: 'flat' },
};
const MF_FG = { hallway: 'locker', classroom: 'desk', office: 'plant', serverRoom: 'doorframe', street: 'doorframe', streetNight: 'doorframe', kitchen: 'doorframe', bedroom: 'plant', bedroomNight: 'plant', park: 'plant', shop: 'desk' };

function mfHas(P, re) { return (P.actions || []).some(a => re.test(String(a).toLowerCase())); }

/* ===================== acting: action beats ===================== */

/** Action phrase → key, for plans without beats (older plans, AI plans, hand-edited actions). */
const MF_ACT_WORDS = [
  ['walk_in', /walks? in|enters|arrives|comes in/], ['walk_away', /walks? away|leaves|walks? off/], ['stand_up', /stands? up|gets up/],
  ['look_around', /look\w* around/], ['knock', /knock/], ['call', /on the phone|\bcall/], ['hug', /\bhug/], ['cry', /\bcr(y|ies)|tears|sob/],
  ['laugh', /laugh|smil|giggl/], ['shout', /shout|yell|scream/], ['shock', /shock|gasp|jumps? back|flinch/], ['facepalm', /head in hands|facepalm|holds? head/],
  ['sleep', /sleep/], ['wake', /wake|wakes|yawn/], ['eat', /\beat/], ['drink', /drink|sip/], ['type', /typ|laptop|computer|keyboard/],
  ['phone', /phone|text|screen|scroll/], ['read', /\bread|paper|letter/], ['write', /writ/], ['money', /money|count|cash/],
  ['give', /\bhand|give|offer/], ['door', /door/], ['wave', /wave/], ['cheer', /cheer|celebrat/], ['run', /\brun|rush|sprint/],
  ['sit', /\bsit|\bsat\b/], ['pace', /pace|pacing/], ['walk', /walk|approach|step/], ['shrug', /shrug/], ['sigh', /sigh|slump/],
  ['nod', /\bnod/], ['shake_head', /shakes? (his |her )?head/], ['point', /point|accus/], ['think', /think|realiz|wonder|chin/],
  ['turn', /turn|look|glanc|stare/], ['talk', /talk|speak|explain|says|tells/],
];
function mfActKey(s) { s = String(s).toLowerCase(); for (const [k, re] of MF_ACT_WORDS) if (re.test(s)) return k; return null; }

/** One-shot actions settle back into acting out the emotion after this many seconds; the rest hold until the next beat. */
const MF_ONESHOT = { shock: 1.6, nod: 1.3, shake_head: 1.5, wave: 1.9, point: 2.4, shrug: 1.7, cheer: 2.0, sit: 1.0, stand_up: 1.0, turn: 1.8, knock: 2.2 };
/** Actions that bring the character into the shot, so they always start the shot. */
const MF_ENTER = { walk_in: 1, run: 1, walk: 1 };
/** State an action leaves behind for the following beats. */
const MF_END = { sit: { sit: 1 }, type: { sit: 1 }, write: { sit: 1 }, eat: { sit: 1 }, stand_up: { sit: 0 }, sleep: { lie: 1 }, wake: { lie: 0 } };
const MF_DESK = { type: 1, write: 1, eat: 1 };

/** Scene-local time a narration word is spoken inside this scene (null when timings are not available). */
function mfWordTime(P, word) {
  if (!word || P.si == null || typeof CAPS === 'undefined' || typeof STORY === 'undefined' || !STORY.starts) return null;
  const norm = s => String(s).toLowerCase().replace(/[^a-z0-9']/g, ''), want = norm(word);
  const s0 = STORY.starts[P.si], s1 = STORY.starts[P.si + 1] ?? Infinity;
  for (const c of CAPS) for (const w of (c.words || [])) if (w.start >= s0 - 0.3 && w.start < s1 && norm(w.w) === want) return w.start - s0;
  return null;
}

/** Timed beats for the shot: [{a, t0, t1}], each starting just before its trigger word. */
function mfSchedule(P, dur) {
  let list = (P.beats && P.beats.length ? P.beats : (P.actions || []).map(a => ({ a: mfActKey(a) }))).filter(b => b && MF_ACTS[b.a]);
  if (!list.length && P.emotion === 'shocked') list = [{ a: 'shock' }];
  list = list.slice(0, Math.max(1, Math.min(4, Math.floor(dur / 1.3))));
  list.sort((x, y) => (MF_ENTER[y.a] ? 1 : 0) - (MF_ENTER[x.a] ? 1 : 0) || (x.a === 'walk_away' ? 1 : 0) - (y.a === 'walk_away' ? 1 : 0));
  const n = list.length, out = [];
  list.forEach((b, i) => {
    const spoken = mfWordTime(P, b.w);
    let t0 = spoken !== null ? spoken - 0.35 : (n === 1 ? Math.min(0.3, dur * 0.1) : i * dur / n);
    if (MF_ENTER[b.a] && i === 0) t0 = 0;
    if (b.a === 'shock' && P.slamAt != null && spoken === null) t0 = P.slamAt - 0.15;
    out.push({ a: b.a, t0 });
  });
  for (let i = 0; i < out.length; i++) out[i].t0 = clamp(Math.max(out[i].t0, i ? out[i - 1].t0 + (MF_ENTER[out[i - 1].a] ? 1.8 : 0.9) : 0), 0, Math.max(0, dur - 0.5));
  const kept = out.filter((b, i) => i === out.length - 1 || out[i + 1].t0 - b.t0 >= 0.3);
  kept.forEach((b, i) => { b.t1 = i < kept.length - 1 ? kept[i + 1].t0 : dur + 10; });
  return kept;
}

/* --- pose helpers (kid rig units: shoulders ±46,140 · hips ±26,250 · feet ±32,352) --- */
const MF_LAP = { L: [[-64, 214], [-34, 246]], R: [[64, 214], [34, 246]], frontL: true, frontR: true };
const MF_STAND_LEGS = { L: [[-26, 250], [-30, 300], [-32, 352]], R: [[26, 250], [30, 300], [32, 352]] };
const MF_SIT_LEGS = { L: [[-26, 250], [-40, 262], [-44, 282]], R: [[26, 250], [40, 262], [44, 282]] };
function mfArms(L, R, o = {}) { return Object.assign({ L, R }, o); }
/** Mirror arms left↔right (actions aimed at a character on the left). */
function mfMirror(a) {
  const m = arm => arm && arm.map(p => [-p[0], p[1]]);
  return { L: m(a.R), R: m(a.L), frontL: a.frontR, frontR: a.frontL, fistL: a.fistR, fistR: a.fistL };
}
function mfLerpPts(a, b, k) { return a.map((p, i) => [lerp(p[0], (b[i] || p)[0], k), lerp(p[1], (b[i] || p)[1], k)]); }
function mfLerpArms(a, b, k) {
  if (!a) return b; if (!b) return a;
  const o = { L: mfLerpPts(a.L, b.L, k), R: mfLerpPts(a.R, b.R, k) };
  for (const f of ['frontL', 'frontR', 'fistL', 'fistR']) o[f] = k < 0.5 ? a[f] : b[f];
  return o;
}
function mfLerpLegs(a, b, k) { a = a || MF_STAND_LEGS; b = b || MF_STAND_LEGS; return { L: mfLerpPts(a.L, b.L, k), R: mfLerpPts(a.R, b.R, k) }; }
/** Blend two poses (k 0 → a, 1 → b) so every change of action is eased, never a jump. */
function mfBlend(a, b, k) {
  if (k >= 1) return b;
  const o = Object.assign({}, k < 0.5 ? a : b);
  for (const f of ['dx', 'dy', 'sit', 'lie', 'headRot', 'headDY', 'sq', 'lean', 'glow']) o[f] = lerp(a[f] || 0, b[f] || 0, k);
  o.arms = mfLerpArms(a.arms || ARMS.rest, b.arms || ARMS.rest, E.io(k));
  o.legs = a.legs || b.legs ? mfLerpLegs(a.legs, b.legs, E.io(k)) : null;
  const fa = a.face || {}, fb = b.face || {};
  o.face = Object.assign({}, k < 0.5 ? fa : fb);
  for (const f of ['lid', 'browLift', 'ma', 'red']) o.face[f] = lerp(fa[f] || 0, fb[f] || 0, k);
  if (fa.look || fb.look) { const la = fa.look || [0, 0], lb = fb.look || [0, 0]; o.face.look = [lerp(la[0], lb[0], k), lerp(la[1], lb[1], k)]; }
  return o;
}
/** Mouth opening for speech: the voice envelope when the renderer provides it, otherwise syllable-like motion. */
function mfVoice(P, t, seed) {
  if (typeof VO_ENV !== 'undefined' && VO_ENV && P.si != null && typeof STORY !== 'undefined') {
    const v = VO_ENV[Math.floor((STORY.starts[P.si] + t) * 30)];
    if (v !== undefined) return clamp(v * 1.4);
  }
  const gap = noise(t * 1.7, seed + 11) > -0.45 ? 1 : 0.08;
  return clamp((0.2 + 0.7 * Math.abs(Math.sin(t * 10.5 + Math.sin(t * 3.3) * 2))) * gap);
}
/** Repeating reach-and-return: 0 at rest, eases to 1, holds, eases back. */
function mfCycle(lt, period, up = 0.32, hold = 0.22) {
  const p = (lt % period) / period;
  return p < up ? E.io(p / up) : p < up + hold ? 1 : p < up * 2 + hold ? 1 - E.io((p - up - hold) / up) : 0;
}
function mfMove(lt, len, dist) {
  const k = seg(lt, 0, len);
  return { k, x: dist * (k < 0.8 ? k / 0.8 * 0.9 : 0.9 + 0.1 * E.o((k - 0.8) / 0.2)) };
}
function mfWalkPose(lt, k, speed, running) {
  const w = running ? run(lt) : walk(lt, speed), settle = seg(k, 0.85, 1);
  return { legs: mfLerpLegs(w.legs, MF_STAND_LEGS, settle), arms: mfLerpArms(w.arms, ARMS.rest, settle), dy: w.bob * (1 - settle) };
}

/* --- props --- */
function mfDesk(c, x, top, floor) {
  rr(c, x - 210, top, 420, 34, 6); shape(c, '#6d5644');
  rr(c, x - 196, top + 34, 392, Math.max(20, floor - top - 34), 4); shape(c, '#5a4636');
  c.save(); c.globalAlpha = 0.25; line(c, [[x - 170, top + 70], [x + 170, top + 70]], 4, '#2a2018'); c.restore();
}
function mfChair(c, x, floor, s) {
  c.save(); c.translate(x, floor); c.scale(s, s);
  rr(c, -78, -262, 156, 214, 18); shape(c, '#4a3a30');
  rr(c, -92, -56, 184, 28, 8); shape(c, '#5c4a3c');
  for (const sx of [-74, 60]) { rr(c, sx, -30, 14, 30, 4); shape(c, '#3a2e26'); }
  c.restore();
}
function mfLaptop(c, x, top, t) {
  rr(c, x - 86, top - 118, 172, 118, 10); shape(c, '#3b404b');
  c.beginPath(); c.arc(x, top - 62, 12, 0, Math.PI * 2); c.fillStyle = 'rgba(220,230,240,0.55)'; c.fill();
  rr(c, x - 100, top - 8, 200, 12, 4); shape(c, '#2b2f37');
}
function mfBowl(c, x, top) {
  c.beginPath(); c.moveTo(x - 62, top - 40); c.quadraticCurveTo(x, top + 22, x + 62, top - 40); c.closePath(); shape(c, '#e8e2d4');
  c.beginPath(); c.ellipse(x, top - 40, 62, 12, 0, 0, Math.PI * 2); shape(c, '#c98f4a', INK, OW * 0.6);
}
function mfNotebook(c, x, top) {
  c.beginPath(); c.moveTo(x - 92, top); c.lineTo(x + 70, top); c.lineTo(x + 92, top - 26); c.lineTo(x - 70, top - 26); c.closePath(); shape(c, '#f1ede2', INK, OW * 0.6);
}
function mfDoor(c, x, floor, open) {
  doorDraw(c, x, floor - 255, 0.85, open);
  if (open > 0.05) { c.save(); c.globalAlpha = 0.18 * open; c.fillStyle = '#f3dfae'; c.beginPath(); c.moveTo(x - 100, floor); c.lineTo(x + 100, floor); c.lineTo(x + 260, floor + 160); c.lineTo(x - 260, floor + 160); c.closePath(); c.fill(); c.restore(); }
}
function mfBed(c, x, pivotY) {
  rr(c, x - 430, pivotY - 170, 42, 330, 10); shape(c, '#5a4636');
  rr(c, x - 400, pivotY + 56, 650, 92, 14); shape(c, '#e9e4da');
  rr(c, x - 400, pivotY + 140, 650, 40, 8); shape(c, '#5a4636');
  c.beginPath(); c.ellipse(x - 262, pivotY + 36, 112, 40, 0, 0, Math.PI * 2); shape(c, '#f4f1ea');
}
function mfBlanket(c, x, pivotY, lie) {
  const left = lerp(x - 200, x - 140, lie), top = lerp(pivotY - 6, pivotY - 66, lie);
  c.beginPath(); c.moveTo(left, top + 30); c.quadraticCurveTo(left + 10, top, left + 60, top); c.lineTo(x + 250, top + 6); c.lineTo(x + 250, pivotY + 150); c.lineTo(left - 10, pivotY + 150); c.closePath();
  shape(c, '#5b6f9a');
  c.save(); c.globalAlpha = 0.25; line(c, [[left + 40, top + 40], [x + 230, top + 46]], 6, '#2c3a5c'); c.restore();
}
function mfTears(c, lt) {
  for (const s of [-1, 1]) {
    const k = ((lt * 0.9 + (s > 0 ? 0.45 : 0)) % 1);
    c.save(); c.globalAlpha = 0.55; line(c, [[s * 40, 20], [s * 44, 20 + k * 70]], 5, '#9fd3f0'); c.restore();
    c.beginPath(); c.arc(s * 44, 26 + k * 74, 7, 0, Math.PI * 2); shape(c, '#9fd3f0', INK, 3);
  }
}
function mfPhoneItem(lt, scale, onEar) {
  return c => {
    if (onEar) { phoneDraw(c, -6, -26, 0.3, { rot: 0.35, screen: '#3a4558' }); return; }
    phoneDraw(c, 0, -34, 0.44 * scale, {
      glow: true, screen: '#7fa3c4',
      content: c3 => {
        c3.save(); rr(c3, -48, -94, 96, 180, 10); c3.clip();
        const off = (lt * 34) % 40;
        for (let i = -1; i < 6; i++) { const y = -84 + i * 40 - off; rr(c3, -38, y, 76, 28, 6); c3.fillStyle = i % 2 ? 'rgba(255,255,255,0.75)' : 'rgba(30,40,60,0.35)'; c3.fill(); }
        c3.restore();
      },
    });
  };
}

/*
 * Action library. Each action returns a pose for the character at beat-local time q.lt:
 *   { arms, legs, face, dx, dy, sit, lie, headRot, headDY, sq, lean, back, handItem, after, onHead, glow, shake }
 * q = { lt, d (beat length), t (scene time), side (+1 other character to the right, −1 left), base:{sit, lie},
 *       emo, seed, vo (mouth 0..1), otherDx (distance to the other character), enter (side to enter/leave from) }
 */
const MF_ACTS = {
  phone(q) {
    const up = E.o(seg(q.lt, 0.05, 0.55)), tap = Math.max(0, Math.sin(q.lt * 5)) * 3;
    return { arms: mfArms([[-58, 212], [-12, 168]], [[58, 212], [16, 152 - tap]], { frontL: true, frontR: true }), headDY: 8 * up,
      face: { look: [0.12 + noise(q.lt * 0.8, q.seed) * 0.15, 0.78], lid: 0.12 }, handItem: mfPhoneItem(q.lt, up), glow: up };
  },
  call(q) {
    const talking = noise(q.lt * 0.6, q.seed + 4) > -0.2;
    return { arms: mfArms(ARMS.rest.L, [[86, 176], [96, 30]], { frontR: true }), headRot: 0.06,
      face: { look: [-0.45, 0.25], mouth: talking ? 'talk' : 'flat', ma: talking ? q.vo : 0 }, handItem: mfPhoneItem(q.lt, 1, true), headDY: Math.sin(q.lt * 2.2) * 3 };
  },
  type(q) {
    const a = Math.max(0, Math.sin(q.lt * 15)) * 7, b = Math.max(0, Math.sin(q.lt * 15 + 2.2)) * 7;
    return { sit: 1, arms: mfArms([[-80, 200], [-84, 206 - a]], [[80, 200], [84, 206 - b]], { frontL: true, frontR: true }),
      face: { look: [noise(q.lt * 0.7, q.seed) * 0.3, 0.32], lid: 0.15 }, headDY: 4, glow: 0.8 };
  },
  write(q) {
    return { sit: 1, arms: mfArms([[-70, 200], [-46, 210]], [[70, 200], [26 + Math.sin(q.lt * 7) * 16, 206 + Math.cos(q.lt * 13) * 3]], { frontL: true, frontR: true }),
      face: { look: [0.1 + Math.sin(q.lt * 0.9) * 0.2, 0.85], lid: 0.2 }, headDY: 10,
      handItem: c => { line(c, [[0, 0], [16, -46]], 6, '#2a2f3a'); } };
  },
  eat(q) {
    const up = mfCycle(q.lt, 2.3), chewing = (q.lt % 2.3) / 2.3 > 0.55;
    return { sit: 1, arms: mfArms([[-70, 202], [-56, 210]], [[70, lerp(204, 168, up)], [lerp(26, 12, up), lerp(208, 62, up)]], { frontL: true, frontR: true }),
      face: { look: [0, lerp(0.7, -0.1, up)], mouth: up > 0.6 ? 'O' : chewing ? 'chew' : 'flat', ma: up > 0.6 ? 0.45 : Math.abs(Math.sin(q.lt * 11)) }, headDY: lerp(6, -2, up),
      handItem: c => { line(c, [[0, 0], [-6, -40]], 6, '#9aa0a8'); c.beginPath(); c.ellipse(-7, -46, 12, 8, 0, 0, Math.PI * 2); shape(c, '#c7ccd2', INK, 4); } };
  },
  drink(q) {
    const up = mfCycle(q.lt, 3.0, 0.25, 0.3);
    return { arms: mfArms(q.base.sit ? MF_LAP.L : ARMS.rest.L, [[70, lerp(206, 160, up)], [lerp(60, 18, up), lerp(214, 66, up)]], { frontR: true, frontL: !!q.base.sit }),
      face: { lid: lerp(0.1, 0.6, up), look: [0, lerp(0.4, -0.2, up)], mouth: 'flat' }, headDY: -4 * up,
      handItem: c => { rr(c, -22, -34, 44, 58, 8); shape(c, '#e8e2d4'); rr(c, 18, -22, 14, 24, 7); shape(c, null, INK, 5); } };
  },
  read(q) {
    const line01 = (q.lt % 1.1) / 1.1, row = Math.floor(q.lt / 1.1) % 5;
    const paper = c => { paperDraw(c, 0, 168, 0.56, { rot: 0.02 }); for (const s of [-1, 1]) { c.beginPath(); c.arc(s * 48, 196, 13, 0, Math.PI * 2); shape(c, '#fff', INK, OW * 0.8); } };
    return { arms: mfArms([[-70, 204], [-48, 196]], [[70, 204], [48, 196]], { frontL: true, frontR: true }), headDY: 6, after: paper,
      face: { look: [lerp(-0.55, 0.55, E.io(line01)), 0.6 + row * 0.05], lid: 0.15 } };
  },
  money(q) {
    const flick = (q.lt % 0.8) / 0.8;
    const bills = c => {
      for (let i = 0; i < 3; i++) billDraw(c, -34 + i * 2, 186 - i * 4, 0.42, 1.35);
      if (flick < 0.6) billDraw(c, lerp(-30, 44, E.io(flick / 0.6)), lerp(184, 196, flick), 0.42, lerp(1.35, 1.6, flick));
      for (let i = 0; i < 2 + Math.floor(q.lt / 0.8) % 4; i++) billDraw(c, 46, 200 - i * 3, 0.42, 1.6);
    };
    return { arms: mfArms([[-66, 206], [-30, 192]], [[66, 206], [lerp(-8, 40, E.io(Math.min(1, flick / 0.6))), 190]], { frontL: true, frontR: true }), after: bills,
      face: { look: [0, 0.8], mouth: 'smirk', lid: 0.2 }, headDY: 6 };
  },
  give(q) {
    const reach = E.io(seg(q.lt, 0.3, 1.0)), pass = E.io(seg(q.lt, 1.2, 1.9)), back = E.io(seg(q.lt, 2.0, 2.6));
    const out = mfArms([[70, 180], [lerp(40, 128, reach), lerp(206, 176, reach)]], [[100, 184], [lerp(60, 150, reach), lerp(206, 186, reach)]], { frontL: true, frontR: true });
    const arms = mfLerpArms(q.side < 0 ? mfMirror(out) : out, ARMS.rest, back);
    return { arms, face: { look: [q.side * 0.7, 0.1], mouth: 'flat' }, world: { kind: 'give', reach, pass } };
  },
  door(q) {
    const ds = q.door, open = E.io(seg(q.lt, 0.55, 1.5)), reach = E.io(seg(q.lt, 0.1, 0.55)) * (1 - E.io(seg(q.lt, 1.6, 2.2)));
    const arm = mfArms(ARMS.rest.L, [[lerp(76, 110, reach), lerp(194, 170, reach)], [lerp(82, 160, reach), lerp(250, 190, reach)]], {});
    return { arms: ds < 0 ? mfMirror(arm) : arm, headRot: ds * 0.08 * reach, face: { look: [ds * 0.8, 0.05] }, world: { kind: 'door', open } };
  },
  knock(q) {
    const ds = q.door, raise = E.io(seg(q.lt, 0.05, 0.4)), hit = [0.5, 0.78, 1.06].some(h => q.lt > h && q.lt < h + 0.1) ? 14 : 0;
    const arm = mfArms(ARMS.rest.L, [[lerp(76, 104, raise), lerp(194, 150, raise)], [lerp(82, 150 + hit, raise), lerp(250, 110, raise)]], { fistR: true });
    return { arms: ds < 0 ? mfMirror(arm) : arm, face: { look: [ds * 0.7, -0.1] }, world: { kind: 'door', open: E.io(seg(q.lt, 1.8, 2.4)) * 0.6 } };
  },
  hug(q) {
    const dist = q.otherDx ? q.otherDx - q.side * 190 : 0, m = mfMove(q.lt, 1.1, dist), wrap = E.io(seg(q.lt, 0.9, 1.4));
    const w = m.k < 1 && dist ? mfWalkPose(q.lt, m.k, 1.8) : {};
    const hugArms = mfArms([[-10, 186], [70, 196]], [[110, 176], [160, 186]], { frontL: true, frontR: true });
    return { dx: m.x, legs: w.legs, dy: w.dy || 0, arms: mfLerpArms(w.arms || ARMS.rest, q.side < 0 ? mfMirror(hugArms) : hugArms, wrap),
      headRot: q.side * 0.08 * wrap, face: { happyEyes: wrap > 0.6, mouth: 'happy', look: [q.side * 0.6, 0] } };
  },
  cry(q) {
    const w = mfCycle(q.lt, 2.6, 0.28, 0.3), sob = noise(q.lt * 2, q.seed) > 0 ? Math.sin(q.lt * 22) * 2.5 : 0;
    return { arms: mfArms(ARMS.rest.L, [[lerp(76, 76, w), lerp(194, 172, w)], [lerp(82, 42, w), lerp(250, 12, w)]], { frontR: w > 0.3 }),
      face: { brows: 'worried', mouth: 'sad', lid: 0.45, look: [0, 0.55] }, headDY: 10, headRot: -0.05, dy: sob, onHead: c => mfTears(c, q.lt) };
  },
  laugh(q) {
    const fade = 1 - seg(q.lt, 2.4, 3.2);
    return { arms: mfArms([[-64, 200], [-18, 224]], [[64, 200], [20, 226]], { frontL: true, frontR: true }), dy: -Math.abs(Math.sin(q.lt * 12)) * 5 * fade,
      headRot: Math.sin(q.lt * 6) * 0.04 * fade - 0.02, face: { happyEyes: fade > 0.3, mouth: fade > 0.3 ? 'grin' : 'happy', brows: 'up' } };
  },
  shout(q) {
    const lean = q.lt < 0.2 ? -0.03 * E.o(q.lt / 0.2) : 0.05 * E.o(seg(q.lt, 0.2, 0.5));
    return { arms: mfArms([[-88, 198], [-98, 262]], [[88, 198], [98, 262]], { fistL: true, fistR: true }), lean: lean * q.side,
      face: { brows: 'angry', mouth: 'O', ma: 0.45 + 0.55 * q.vo, red: 0.5, look: [q.side * 0.7, 0] }, headRot: noise(q.lt * 9, q.seed) * 0.03, shake: 0.22 };
  },
  talk(q) {
    const G = [mfArms(ARMS.rest.L, [[86, 182], [124, 152]]), mfArms([[-86, 182], [-120, 160]], [[86, 182], [120, 160]]), mfArms([[-86, 182], [-118, 150]], ARMS.rest.R)];
    const g = Math.floor(q.lt / 1.3), m = E.io(seg(q.lt % 1.3, 0.85, 1.3));
    return { arms: mfLerpArms(G[g % 3], G[(g + 1) % 3], m), headRot: Math.sin(q.lt * 3.6) * 0.03, face: { mouth: 'talk', ma: q.vo, brows: g % 2 ? 'raise' : undefined, look: [q.side * 0.6, 0] } };
  },
  think(q) {
    return { arms: mfArms(ARMS.rest.L, [[66, 200], [30, 70 + Math.sin(q.lt * 3) * 3]], { fistR: true, frontR: true }), headRot: 0.06,
      face: { brows: 'raise', look: [0.5, -0.6], mouth: q.lt > 1.4 && (q.emo === 'sneaky' || q.emo === 'determined') ? 'smirk' : 'flat' } };
  },
  point(q) {
    const arm = mfArms(ARMS.rest.L, [[102, 150], [166, 136]]);
    return { arms: q.side < 0 ? mfMirror(arm) : arm, lean: 0.02 * q.side, face: { brows: q.emo === 'angry' ? 'angry' : 'stern', look: [q.side * 0.85, 0], mouth: 'flat' } };
  },
  shock(q) {
    const jump = E.o(seg(q.lt, 0.1, 0.45)) - E.io(seg(q.lt, 1.1, 1.6));
    return { sq: q.lt < 0.1 ? 0.08 : -0.1 * (1 - seg(q.lt, 0.1, 0.6)), dx: -q.side * 46 * jump, dy: -26 * Math.sin(seg(q.lt, 0.1, 0.5) * Math.PI),
      arms: mfArms([[-92, 150], [-62, 70]], [[92, 150], [62, 70]], { frontL: true, frontR: true }),
      face: { brows: 'up', browLift: 1, mouth: 'O', ma: 0.8, lid: 0, look: [q.side * 0.3, -0.1] }, shake: 0.12 };
  },
  facepalm(q) {
    return { arms: mfArms([[-86, 162], [-56, 32]], [[86, 162], [56, 32]], { frontL: true, frontR: true }), headDY: 12, headRot: Math.sin(q.lt * 1.8) * 0.05,
      face: { brows: 'worried', mouth: 'wavy', lid: 0.6, look: [0, 0.6] } };
  },
  sigh(q) {
    const drop = E.io(seg(q.lt, 0.2, 1.2));
    return { arms: mfArms([[-72, 200], [-76, 262]], [[72, 200], [76, 262]]), headDY: 14 * drop, dy: 6 * drop,
      face: { lid: 0.55 * drop, mouth: q.lt > 0.3 && q.lt < 1.1 ? 'O' : 'sad', ma: 0.2, brows: 'worried', look: [0, 0.4] } };
  },
  shrug(q) {
    return { arms: ARMS.shrug, headDY: 6, headRot: 0.08, face: { brows: 'raise', mouth: 'wavy', look: [q.side * 0.4, 0] } };
  },
  nod(q) {
    return { headDY: Math.sin(q.lt * 9) * 7 * (1 - seg(q.lt, 0.9, 1.3)), face: { lid: 0.2, mouth: q.emo === 'sad' ? 'flat' : 'smirk', look: [q.side * 0.5, 0] } };
  },
  shake_head(q) {
    return { headRot: Math.sin(q.lt * 10) * 0.12 * (1 - seg(q.lt, 0.8, 1.4)), face: { brows: 'stern', mouth: 'flat', look: [0, 0.1] } };
  },
  wave(q) {
    const up = E.io(seg(q.lt, 0, 0.35));
    return { arms: mfArms(ARMS.rest.L, [[lerp(76, 98, up), lerp(194, 110, up)], [lerp(82, 120 + Math.sin(q.lt * 9) * 26, up), lerp(250, 24, up)]]), face: { brows: 'up', mouth: 'happy', look: [q.side * 0.5, 0] } };
  },
  cheer(q) {
    return { arms: ARMS.cheer, dy: -Math.abs(Math.sin(seg(q.lt, 0, 1.2) * Math.PI * 2)) * 40, face: { happyEyes: true, mouth: 'grin' } };
  },
  look_around(q) {
    const eyes = Math.sin((q.lt + 0.3) * 2.2), head = Math.sin(q.lt * 2.2);
    return { arms: mfArms([[-70, 192], [-40, 232]], [[70, 192], [40, 232]], { frontL: true, frontR: true }), dy: 6, headRot: head * 0.16,
      face: { brows: 'worried', look: [eyes * 0.9, 0], mouth: q.emo === 'sneaky' ? 'smirk' : 'flat' } };
  },
  turn(q) {
    const eyeK = E.o(seg(q.lt, 0, 0.4)), headK = E.heavy(seg(q.lt, 0.25, 1.2));
    return { headRot: q.side * 0.12 * headK, face: { look: [lerp(-q.side * 0.3, q.side * 0.85, eyeK), 0] } };
  },
  walk_in(q) {
    const m = mfMove(q.lt, Math.min(q.d - 0.2, 2.4), 620), w = mfWalkPose(q.lt, m.k, 1.8);
    return { dx: q.enter * (620 - m.x), legs: w.legs, arms: w.arms, dy: w.dy, face: { look: [m.k < 0.9 ? -q.enter * 0.6 : q.side * 0.5, 0] } };
  },
  walk(q) {
    const m = mfMove(q.lt, Math.min(q.d - 0.2, 1.8), 260), w = mfWalkPose(q.lt, m.k, 1.7);
    return { dx: q.enter * (260 - m.x), legs: w.legs, arms: w.arms, dy: w.dy, face: { look: [-q.enter * 0.5, 0] } };
  },
  run(q) {
    const m = mfMove(q.lt, Math.min(q.d - 0.2, 1.3), 760), w = mfWalkPose(q.lt, m.k, 3, true), pant = m.k >= 1 ? Math.sin(q.lt * 8) * 4 : 0;
    return { dx: q.enter * (760 - m.x), legs: w.legs, arms: w.arms, dy: w.dy + pant, lean: -q.enter * 0.06 * (1 - m.k),
      face: { mouth: 'O', ma: 0.35, brows: 'worried', look: [-q.enter * 0.6, 0] } };
  },
  walk_away(q) {
    const k = seg(q.lt, 0.35, 3.2), w = walk(q.lt, 1.7);
    return { dx: q.enter * 760 * k, legs: k > 0 ? w.legs : null, arms: k > 0 ? w.arms : ARMS.rest, dy: k > 0 ? w.bob : 0, back: q.lt > 0.3 };
  },
  pace(q) {
    const ph = q.lt * 0.9, moving = Math.abs(Math.cos(ph)) > 0.15, w = walk(q.lt, 1.6);
    return { dx: Math.sin(ph) * 140, legs: moving ? w.legs : null, dy: moving ? w.bob : 0, arms: ARMS.fold,
      face: { brows: 'worried', look: [Math.cos(ph) > 0 ? 0.6 : -0.6, 0.2], mouth: 'flat' } };
  },
  sit(q) { const k = E.io(seg(q.lt, 0.1, 0.9)); return { sit: k, arms: mfLerpArms(ARMS.rest, MF_LAP, k), dy: -10 * Math.sin(seg(q.lt, 0, 0.3) * Math.PI) }; },
  stand_up(q) { const k = E.io(seg(q.lt, 0.15, 0.85)); return { sit: 1 - k, sq: -0.04 * Math.sin(k * Math.PI) }; },
  sleep(q) { return { lie: 1, face: { lid: 1, mouth: 'flat' }, dy: Math.sin(q.lt * 1.5) * 2 }; },
  wake(q) {
    const up = E.io(seg(q.lt, 0.5, 1.4)), stretch = E.io(seg(q.lt, 1.6, 2.0)) * (1 - E.io(seg(q.lt, 2.6, 3.0)));
    return { lie: 1 - up, arms: mfLerpArms(ARMS.rest, ARMS.up, stretch),
      face: { lid: q.lt < 0.5 ? 1 : lerp(0.6, 0.25, seg(q.lt, 2.8, 3.4)), mouth: stretch > 0.3 ? 'O' : 'flat', ma: 0.8 * stretch, look: [0, 0.1] } };
  },
};

/** Acting out the emotion between and after actions, so nobody ever just stands still. */
function mfIdle(q) {
  const t = q.t, s = q.base;
  const p = { dx: noise(t * 0.25, q.seed + 3) * 6, face: { look: [q.side * 0.55, 0] } };
  switch (q.emo) {
    case 'worried': p.arms = mfArms([[-56, 205], [-10, 224 + Math.sin(t * 3) * 3]], [[56, 205], [12, 222 - Math.sin(t * 3) * 3]], { frontL: true, frontR: true }); p.face.look = [Math.sin(t * 0.9) * 0.6, 0.15]; break;
    case 'sad': p.headDY = 10; p.face.look = [0, 0.5]; p.arms = mfArms([[-72, 200], [-76, 262]], [[72, 200], [76, 262]]); break;
    case 'angry': p.arms = Object.assign({}, ARMS.rest, { fistL: true, fistR: true }); p.dy = Math.sin(t * 5) * 3; p.face.red = 0.2; break;
    case 'sneaky': p.arms = mfArms([[-50, 205], [-6 + Math.sin(t * 8) * 6, 215]], [[50, 205], [6 - Math.sin(t * 8) * 6, 215]], { frontL: true, frontR: true }); p.face.look = [q.side * 0.8, 0.1]; break;
    case 'confident': p.arms = ARMS.hips; break;
    case 'afraid': p.arms = mfArms([[-66, 190], [-36, 232]], [[66, 190], [36, 232]], { frontL: true, frontR: true }); p.dx += noise(t * 18, q.seed) * 2; p.face.look = [Math.sin(t * 1.4) * 0.7, 0]; break;
    case 'shocked': p.arms = mfArms([[-80, 170], [-70, 150]], [[80, 170], [70, 150]]); break;
    case 'determined': p.arms = Object.assign({}, ARMS.rest, { fistL: true, fistR: true }); break;
    case 'neutral': if (q.d > 3 && q.lt > 1.6) p.arms = ARMS.fold; break;
  }
  if (s.sit && !p.arms) p.arms = MF_LAP;
  if (s.lie) return Object.assign(p, MF_ACTS.sleep(q));
  return p;
}

/** Hero pose at scene time t from the scheduled beats (with eased hand-offs between actions). */
function mfHeroPose(P, t, beats, info) {
  let base = { sit: 0, lie: 0 };
  if (beats[0] && (beats[0].a === 'sleep' || beats[0].a === 'wake')) base.lie = 1;
  if (beats[0] && MF_DESK[beats[0].a]) base.sit = 1;
  const states = beats.map(b => { const before = Object.assign({}, base); base = Object.assign({}, base, MF_END[b.a] || {}); return before; });
  let cur = -1;
  beats.forEach((b, i) => { if (t >= b.t0) cur = i; });
  const q = (i, b) => Object.assign({}, info, { lt: t - b.t0, d: b.t1 - b.t0, t, base: states[i] || base });
  const fill = (pose, st) => Object.assign({ sit: st.sit, lie: st.lie }, pose);
  const idleAt = (st) => fill(mfIdle(Object.assign({}, info, { lt: t, d: P.dur, t, base: st })), st);
  if (cur < 0) return idleAt(states[0] || base);
  const b = beats[cur], qq = q(cur, b), after = cur + 1 < states.length ? states[cur + 1] : base;
  let pose = fill(MF_ACTS[b.a](qq), qq.base);
  if (pose.shake !== undefined) pose.shakeAt = b.t0 + pose.shake;
  const len = MF_ONESHOT[b.a];
  if (len && qq.lt > len) pose = mfBlend(pose, idleAt(Object.assign({}, after)), E.io(seg(qq.lt, len, len + 0.6)));
  if (qq.lt < 0.4) {
    const prev = cur > 0 ? fill(MF_ACTS[beats[cur - 1].a](q(cur - 1, beats[cur - 1])), states[cur - 1]) : idleAt(qq.base);
    if (cur > 0 && MF_ONESHOT[beats[cur - 1].a] && t - beats[cur - 1].t0 > MF_ONESHOT[beats[cur - 1].a]) return mfBlend(idleAt(qq.base), pose, E.io(qq.lt / 0.4));
    pose = mfBlend(prev, pose, E.io(qq.lt / 0.4));
  }
  pose.beat = b; pose.beatT = qq.lt;
  return pose;
}

/** What another character does while the hero acts: listen, receive, hug back, wave back, or speak (P.otherAct). */
function mfOtherPose(P, t, heroPose, info) {
  const q = Object.assign({}, info, { lt: t, d: P.dur, t, base: { sit: 0, lie: 0 }, emo: P.otherEmotion || 'stern' });
  const a = heroPose.beat ? heroPose.beat.a : null, lt = heroPose.beatT || 0;
  if (P.otherAct === 'talk' || P.otherAct === 'shout' || P.otherAct === 'laugh') return MF_ACTS[P.otherAct](q);
  const p = mfIdle(q);
  p.face = Object.assign({ brows: 'stern', mouth: 'flat' }, p.face, { look: [info.side * 0.7, 0] });
  if (a === 'give') { const r = E.io(seg(lt, 0.9, 1.6)); const arm = mfArms(ARMS.rest.L, [[lerp(76, 104, r), lerp(194, 180, r)], [lerp(82, 150, r), lerp(250, 196, r)]], { frontR: true }); p.arms = info.side < 0 ? mfMirror(arm) : arm; }
  else if (a === 'hug') { const w = E.io(seg(lt, 0.9, 1.4)); const arm = mfArms([[-10, 186], [70, 196]], [[110, 176], [160, 186]], { frontL: true, frontR: true }); p.arms = mfLerpArms(p.arms || ARMS.rest, info.side < 0 ? mfMirror(arm) : arm, w); p.face.happyEyes = w > 0.6; p.face.mouth = 'happy'; }
  else if (a === 'wave' && lt > 0.5) return MF_ACTS.wave(Object.assign(q, { lt: lt - 0.5 }));
  else if (a === 'shout' || a === 'point') { p.lean = -info.side * 0.02; p.face.brows = 'worried'; p.face.browLift = 0.4; }
  else if (a === 'talk' || a === 'call') p.headDY = Math.max(0, Math.sin(t * 2.2)) * 5;
  return p;
}

/* --- mapping kid-rig poses onto the suit rig (shoulders ±66,140 · hips ±30,330 · feet ≈ 500) --- */
function mfSuitArm(arm) { return arm && arm.map(([x, y]) => [x + Math.sign(x) * 20, y > 140 ? 140 + (y - 140) * 1.6 : y]); }
function mfSuitArms(a) { return a && Object.assign({}, a, { L: mfSuitArm(a.L), R: mfSuitArm(a.R) }); }
function mfSuitLegs(l) { return l && { L: l.L.map(([x, y]) => [x * 1.15, 330 + (y - 250) * 1.65]), R: l.R.map(([x, y]) => [x * 1.15, 330 + (y - 250) * 1.65]) }; }

/** Draw one character with a pose. (x, y) is the standing mark; the pose moves, seats, lays down or leans them. */
function mfDrawPerson(c, ch, x, y, s, pose, face, extra) {
  const suit = ch.kind === 'suit', drop = (suit ? 90 : 70) * (pose.sit || 0) * s, lie = pose.lie || 0;
  const X = x + (pose.dx || 0), Y = y + drop + (pose.dy || 0) + (extra.bob || 0);
  let legs = pose.legs || null;
  if (!legs && pose.sit > 0) legs = mfLerpLegs(MF_STAND_LEGS, MF_SIT_LEGS, pose.sit);
  const arms = pose.arms ? (suit ? mfSuitArms(pose.arms) : pose.arms) : (ch.def && ch.def.arms) || undefined;
  const o = { x: X, y: Y, s, face, arms, legs: suit ? mfSuitLegs(legs) : legs, headRot: (pose.headRot || 0) + (extra.headRot || 0),
    headDY: pose.headDY || 0, sq: pose.sq || 0, handItem: pose.handItem, after: pose.after, back: pose.back, flip: extra.flip, noLegs: extra.noLegs };
  if (pose.onHead) o.onHead = pose.onHead;
  c.save();
  if (lie) { const px = X, py = y + 250 * s; c.translate(px, py); c.rotate(-Math.PI / 2 * lie); c.translate(-px, -py); }
  if (pose.lean) { const fy = Y + (suit ? 500 : 352) * s; c.translate(X, fy); c.rotate(pose.lean); c.translate(-X, -fy); }
  if (suit) drawSuit(c, Object.assign({}, ch.def || {}, o, { s: s * 1.05 }));
  else if (ch.kind === 'kiki') drawChar(c, kiki(o));
  else drawChar(c, Object.assign({ shirt: '#5b6f9a', hair: 'bob', hairCol: '#3a2a20' }, ch.def || {}, o));
  c.restore();
  if (pose.glow > 0.05) {
    const g = c.createRadialGradient(X, Y + 60 * s, 10, X, Y + 60 * s, 220 * s);
    g.addColorStop(0, `rgba(150,195,240,${0.22 * pose.glow})`); g.addColorStop(1, 'rgba(150,195,240,0)');
    c.save(); c.globalCompositeOperation = 'lighter'; c.fillStyle = g; c.fillRect(X - 240 * s, Y - 200 * s, 480 * s, 520 * s); c.restore();
  }
}

function mfStage(ctx, t, P) {
  const dur = P.dur || 4, k = clamp(t / dur), seed = P.seed || 1;
  const cast = (P.cast && P.cast.length ? P.cast : [{ kind: 'kiki', name: 'Kiki' }]).slice(0, 3);
  const xs = cast.length === 1 ? [540] : cast.length === 2 ? (LANDSCAPE ? [280, 800] : [340, 760]) : (LANDSCAPE ? [140, 540, 940] : [250, 540, 830]);
  const kikiAt = cast.findIndex(c => c.kind === 'kiki');
  const hi = P.hero != null && cast[P.hero] ? P.hero : kikiAt >= 0 ? kikiAt : 0; // P.hero: the story's protagonist (Kiki is then a guest)
  const heroKind = cast[hi].kind, heroY = heroKind === 'suit' ? 800 : 1000;
  const floorY = heroY + (heroKind === 'suit' ? 525 : 352);
  const otherI = cast.length > 1 ? (hi === 0 ? 1 : hi - 1) : -1;
  const side = otherI >= 0 ? (xs[otherI] > xs[hi] ? 1 : -1) : (seed % 2 ? 1 : -1);

  /* acting: beats for the hero, reactions for everyone else */
  const beats = mfSchedule(P, dur), used = {};
  beats.forEach(b => { used[b.a] = 1; });
  const desk = used.type || used.write || used.eat, chair = desk || used.sit || used.stand_up, bed = used.sleep || used.wake, door = used.door || used.knock;
  const info = { side, emo: P.emotion || 'neutral', seed, vo: mfVoice(P, t, seed), otherDx: otherI >= 0 ? xs[otherI] - xs[hi] : 0,
    enter: otherI >= 0 ? -side : (seed % 2 ? -1 : 1), door: otherI >= 0 ? -side : (seed % 2 ? 1 : -1) };
  const hp = mfHeroPose(P, t, beats, info);

  /* camera from shot + movement, following the acting (walks, sitting down, lying in bed) */
  const base = LANDSCAPE ? {
    // landscape: the frame is 1920x1080 over the same 1080x1920 world, so shots sit lower and wider
    wide: { z: 0.98, cy: 1060 }, medium: { z: 1.2, cy: heroY + 160 }, close_up: { z: 1.8, cy: heroY + 60 },
    extreme_close_up: { z: 2.7, cy: heroY + 12 }, over_the_shoulder: { z: 1.2, cy: heroY + 140 },
    pov: { z: 0.9, cy: 1000 }, low_angle: { z: 1.15, cy: heroY + 290 }, high_angle: { z: 1.05, cy: heroY - 40 },
  }[P.shot] || { z: 1.1, cy: heroY + 160 } : {
    wide: { z: 0.95, cy: 980 }, medium: { z: 1.25, cy: heroY + 130 }, close_up: { z: 2.0, cy: heroY + 30 },
    extreme_close_up: { z: 3.0, cy: heroY + 6 }, over_the_shoulder: { z: 1.35, cy: heroY + 90 },
    pov: { z: 1.0, cy: 960 }, low_angle: { z: 1.3, cy: heroY + 300 }, high_angle: { z: 1.15, cy: heroY - 120 },
  }[P.shot] || { z: 1.2, cy: heroY + 100 };
  // hands-and-props actions need the hands in frame, so an extreme close-up opens up to a close-up
  const handsBusy = beats.some(b => /phone|type|write|eat|drink|read|money|give|door|knock|hug|cheer|wave|cry|facepalm|walk|run|pace|sit|stand|sleep|wake/.test(b.a));
  if (handsBusy && P.shot === 'extreme_close_up') { base.z = LANDSCAPE ? 1.8 : 2.0; base.cy = heroY + 40; }
  if (handsBusy && P.shot === 'close_up') base.cy += 50;
  const group = LANDSCAPE && cast.length > 2 && P.shot !== 'close_up' && P.shot !== 'extreme_close_up';
  const cx = P.shot === 'wide' || P.shot === 'pov' || group ? 540 : lerp(540, xs[hi], 0.85);
  if (group) base.z = Math.min(base.z, 1.1); // keep all three characters in frame
  const a = { z: base.z, cx, cy: base.cy, rot: P.shot === 'low_angle' ? -0.03 : P.shot === 'high_angle' ? 0.02 : 0 }, b = { ...a };
  switch (P.move) {
    case 'slow_push_in': b.z = a.z * 1.12; break;
    case 'slow_pull_out': a.z = b.z * 1.15; break;
    case 'pan_left': a.cx += 70; b.cx -= 70; break;
    case 'pan_right': a.cx -= 70; b.cx += 70; break;
    case 'track': a.cx -= 40; b.cx += 40; break;
    case 'dolly_zoom': b.z = a.z * 1.35; break;
    default: b.z = a.z * 1.03;
  }
  const c = camKeys(t, [[0, a], [dur, b]], E.heavy);
  const follow = P.shot === 'wide' || P.shot === 'pov' || group ? 0.3 : 0.55;
  c.cx += clamp((hp.dx || 0) * follow, -360, 360) - 150 * (hp.lie || 0);
  c.cy += 56 * (hp.sit || 0) + 200 * (hp.lie || 0);  const hand = P.move === 'handheld_drift' || P.emotion === 'afraid' || P.look === 'tense' ? handheld(t, 7, seed) : handheld(t, 2.5, seed);
  const shake = hp.shakeAt != null ? shk(t, hp.shakeAt, 14, 0.4) : P.slamAt != null ? shk(t, P.slamAt, 16, 0.4) : null;
  cam(ctx, camMix(c, hand, shake));

  const bgFn = BACKGROUNDS[P.bg] || BACKGROUNDS.hallway;
  const focus = P.shot === 'close_up' || P.shot === 'extreme_close_up' ? { bgBlur: 5, fgBlur: 8 } : { bgBlur: 0, fgBlur: 5 };
  const hx = xs[hi], heroDrawX = hx + (hp.dx || 0), pivotY = heroY + 250;

  layers(ctx, t, {
    bg: c2 => { bgFn(c2, t, { flicker: P.look === 'tense' || P.look === 'night' }); if (P.look === 'payoff' || P.look === 'warm') rays(c2, t, 900, 80); },
    mid: c2 => {
      if (door) mfDoor(c2, hx + info.door * 240, floorY, hp.world && hp.world.kind === 'door' ? hp.world.open : beats.some(bt => bt.a === 'door' && t >= bt.t1) ? 1 : 0);
      if (bed) mfBed(c2, hx, pivotY);
      if (chair) mfChair(c2, hx, floorY, heroKind === 'suit' ? 1.15 : 1);
      cast.forEach((ch, i) => {
        const isHero = i === hi, al = alive(t, seed * 7 + i);
        const toward = isHero ? side : (xs[hi] + (hp.dx || 0) > xs[i] ? 1 : -1);
        const pose = isHero ? hp : mfOtherPose(P, t, hp, Object.assign({}, info, { side: toward, seed: seed + i * 13, vo: mfVoice(P, t, seed + i) }));
        const emo = isHero ? P.emotion : (P.otherEmotion || 'stern');
        const face = Object.assign({}, MF_FACES[emo] || MF_FACES.neutral, pose.face || {});
        if (!pose.face || !pose.face.look) face.look = [toward * 0.6, al.look[1]];
        face.look = [face.look[0] + al.look[0] * 0.3, face.look[1]];
        if ((face.lid || 0) < 0.95) face.blink = al.blink;
        const flip = ch.kind !== 'kiki' && cast.length > 1 && toward < 0;
        const y = ch.kind === 'suit' ? 800 : 1000, sc = ch.kind === 'suit' ? 1 : ch.kind === 'kiki' ? 1 : 0.95;
        mfDrawPerson(c2, ch, xs[i], y, sc, pose, face, { bob: al.bob, headRot: al.headRot, flip, noLegs: isHero && bed });
      });
      // props in front of the hero: desk work, bed blanket, a handed-over object
      const top = heroY + 70 * (hp.sit || 0) + (heroKind === 'suit' ? 300 : 215);
      if (desk) {
        mfDesk(c2, hx, top, floorY);
        if (used.type) mfLaptop(c2, hx, top, t);
        if (used.eat) mfBowl(c2, hx - 30, top);
        if (used.write) mfNotebook(c2, hx + 10, top);
      }
      if (bed) mfBlanket(c2, hx, pivotY, hp.lie || 0);
      if (hp.world && hp.world.kind === 'give' && otherI >= 0) {
        const from = [heroDrawX + side * 140, heroY + 190], to = [xs[otherI] - side * 70, (cast[otherI].kind === 'suit' ? 800 : 1000) + 200];
        const p = hp.world.pass, held = hp.world.reach > 0.05 || p > 0;
        if (held) { c2.save(); c2.translate(lerp(from[0], to[0], p), lerp(from[1], to[1], p) - Math.sin(p * Math.PI) * 30); rr(c2, -50, -40, 100, 80, 8); shape(c2, '#c0392b'); line(c2, [[0, -40], [0, 40]], 8, '#f2c230'); c2.restore(); }
      }
    },
    // foreground silhouettes stay pinned to the frame edge (screen space) with a little parallax
    fg: c2 => {
      if (P.shot === 'pov' || P.shot === 'extreme_close_up') return;
      const cc = currentCam();
      c2.save(); c2.setTransform(1, 0, 0, 1, clamp(-(cc.cx - 540) * 0.35 - (cc.sx || 0) * 1.4, -90, 90), 0);
      fgSilhouette(c2, MF_FG[P.bg] || 'doorframe', seed % 2 ? 'left' : 'right');
      c2.restore();
    },
  }, focus);

  if (P.shot === 'over_the_shoulder' && cast.length === 2) {
    const other = cast[hi === 0 ? 1 : 0];
    ots(ctx, other.kind === 'kid' ? 'kid' : other.kind, hi === 0 ? 'right' : 'left', { char: other.def || {} });
  }
  if (P.shot === 'pov') {
    ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.fillStyle = 'rgba(0,0,0,0.55)'; ctx.fillRect(0, 0, SW, SH);
    phoneDraw(ctx, SW / 2, SH * 0.45, (3.2 + k * 0.2) * (LANDSCAPE ? 0.62 : 1), { glow: true, screen: '#6d8fae', content: c3 => { if (P.kw) txt(c3, String(P.kw).toUpperCase(), 0, 0, 22, '#0d1220', { maxW: 80 }); } });
    ctx.restore();
  }
  if (P.look === 'night' || P.look === 'tense') dust(ctx, t, 0.7);
  else dust(ctx, t, 0.4);
  if (P.bg === 'streetNight' && seed % 3 === 0) rain(ctx, t, 0.8);
}
