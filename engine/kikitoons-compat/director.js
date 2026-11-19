/*
 * MotionForge template director runtime (story-level helper, injected at the top of story files).
 * mfStage(ctx, t, P) stages one planned scene with the documented engine API only:
 *   P = { dur, bg, look, shot, move, emotion, actions[], cast:[{kind, def, name}], hero?, seed, fg, kw, slamAt }
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
};
const MF_FG = { hallway: 'locker', classroom: 'desk', office: 'plant', serverRoom: 'doorframe', street: 'doorframe', streetNight: 'doorframe', kitchen: 'doorframe', bedroom: 'plant', bedroomNight: 'plant', park: 'plant', shop: 'desk' };

function mfHas(P, re) { return (P.actions || []).some(a => re.test(String(a).toLowerCase())); }

function mfStage(ctx, t, P) {
  const dur = P.dur || 4, k = clamp(t / dur), seed = P.seed || 1;
  const cast = (P.cast && P.cast.length ? P.cast : [{ kind: 'kiki', name: 'Kiki' }]).slice(0, 3);
  const xs = cast.length === 1 ? [540] : cast.length === 2 ? (LANDSCAPE ? [280, 800] : [340, 760]) : (LANDSCAPE ? [140, 540, 940] : [250, 540, 830]);
  const walking = mfHas(P, /walk|enter|approach|leav/), running = mfHas(P, /\brun|chase|rush/);
  const travel = running ? 160 : walking ? 90 : 0;
  const hero = cast.findIndex(c => c.kind === 'kiki');
  const hi = P.hero != null && cast[P.hero] ? P.hero : hero >= 0 ? hero : 0; // P.hero: the story's protagonist (Kiki is then a guest)
  const heroX = xs[hi] + (travel ? lerp(-travel, travel * 0.4, E.io(k)) : 0);
  const heroY = cast[hi].kind === 'suit' ? 800 : 1000;

  /* camera from shot + movement */
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
  const group = LANDSCAPE && cast.length > 2 && P.shot !== 'close_up' && P.shot !== 'extreme_close_up';
  const cx = P.shot === 'wide' || P.shot === 'pov' || group ? 540 : lerp(540, heroX, 0.85);
  if (group) base.z = Math.min(base.z, 1.1); // keep all three characters in frame
  let a = { z: base.z, cx, cy: base.cy, rot: P.shot === 'low_angle' ? -0.03 : P.shot === 'high_angle' ? 0.02 : 0 }, b = { ...a };
  switch (P.move) {
    case 'slow_push_in': b.z = a.z * 1.12; break;
    case 'slow_pull_out': a.z = b.z * 1.15; break;
    case 'pan_left': a.cx += 70; b.cx -= 70; break;
    case 'pan_right': a.cx -= 70; b.cx += 70; break;
    case 'track': a.cx = heroX - 40; b.cx = heroX + 40; break;
    case 'dolly_zoom': b.z = a.z * 1.35; break;
    default: b.z = a.z * 1.03;
  }
  if (travel && P.move !== 'pan_left' && P.move !== 'pan_right') { a.cx = lerp(540, heroX - travel * 0.6, 0.7); b.cx = lerp(540, heroX, 0.85); }
  const c = camKeys(t, [[0, a], [dur, b]], E.heavy);
  const hand = P.move === 'handheld_drift' || P.emotion === 'afraid' || P.look === 'tense' ? handheld(t, 7, seed) : handheld(t, 2.5, seed);
  const shake = P.slamAt != null ? shk(t, P.slamAt, 16, 0.4) : null;
  cam(ctx, camMix(c, hand, shake));

  const bgFn = BACKGROUNDS[P.bg] || BACKGROUNDS.hallway;
  const focus = P.shot === 'close_up' || P.shot === 'extreme_close_up' ? { bgBlur: 5, fgBlur: 8 } : { bgBlur: 0, fgBlur: 5 };

  layers(ctx, t, {
    bg: c2 => { bgFn(c2, t, { flicker: P.look === 'tense' || P.look === 'night' }); if (P.look === 'payoff' || P.look === 'warm') rays(c2, t, 900, 80); },
    mid: c2 => {
      cast.forEach((ch, i) => {
        const isHero = i === hi;
        const al = alive(t, seed * 7 + i);
        const other = cast.length > 1 ? xs[(i + 1) % cast.length] : null;
        const lookX = other !== null ? (other > xs[i] ? 0.7 : -0.7) : al.look[0];
        // eyes lead, then the head turns
        const turning = isHero && mfHas(P, /turn|look/);
        const eyeK = turning ? E.o(seg(t, 0.2, 0.6)) : 1, headK = turning ? E.heavy(seg(t, 0.45, 1.4)) : 1;
        const face = Object.assign({}, MF_FACES[isHero ? P.emotion : (P.otherEmotion || 'stern')] || MF_FACES.neutral);
        if (!isHero && !MF_FACES[P.otherEmotion]) { face.brows = 'stern'; face.mouth = 'flat'; }
        face.look = [lerp(-lookX, lookX, eyeK), (face.look ? face.look[1] : 0) + (P.emotion === 'sad' && isHero ? 0.4 : al.look[1])];
        face.blink = al.blink;
        const shocked = isHero && P.emotion === 'shocked';
        const sq = shocked ? -0.08 * Math.sin(seg(t, 0.15, 0.5) * Math.PI) : 0;
        let arms = ARMS.rest, legs, bob = al.bob, x = xs[i], handItem;
        if (isHero && travel) { const w = running ? run(t) : walk(t, 1.5); legs = w.legs; arms = w.arms; bob = w.bob; x = heroX; }
        else if (isHero && mfHas(P, /phone|text|screen/)) { arms = ARMS.phone; handItem = c3 => phoneDraw(c3, 0, -30, 0.45, { glow: true, screen: '#7fa3c4' }); }
        else if (isHero && mfHas(P, /point|accus/)) arms = ARMS.point;
        else if (isHero && mfHas(P, /think|realiz|wonder/)) arms = ARMS.think;
        else if (isHero && P.emotion === 'confident') arms = ARMS.hips;
        else if (isHero && P.emotion === 'angry') arms = { ...ARMS.rest, fistL: true, fistR: true };
        else if (isHero && (P.emotion === 'afraid' || shocked)) arms = { L: [[-80, 170], [-70, lerp(250, 150, E.o(seg(t, 0.1, 0.5)))]], R: [[80, 170], [70, lerp(250, 150, E.o(seg(t, 0.1, 0.5)))]] };
        const headRot = (turning ? lerp(-0.22 * Math.sign(lookX || 1), 0.05 * Math.sign(lookX || 1), headK) : al.headRot) + (P.emotion === 'sad' && isHero ? -0.05 : 0);
        const headDY = P.emotion === 'sad' && isHero ? 10 : 0;
        const flip = ch.kind !== 'kiki' && other !== null && other < xs[i];
        if (ch.kind === 'suit') drawSuit(c2, Object.assign({}, ch.def || {}, { x, y: 800 + bob * 0.6, s: 1.05, face, headRot, flip, arms: ch.def && ch.def.arms ? ch.def.arms : undefined }));
        else if (ch.kind === 'kiki') drawChar(c2, kiki({ x, y: 1000 + bob, s: 1, face, arms, legs, headRot, headDY, sq, handItem }));
        else drawChar(c2, Object.assign({ shirt: '#5b6f9a', hair: 'bob', hairCol: '#3a2a20' }, ch.def || {}, { x, y: 1000 + bob, s: 0.95, face, headRot, flip }));
      });
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

