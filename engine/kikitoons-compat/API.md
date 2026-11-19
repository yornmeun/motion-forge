# Kikitoons-compatible engine API (MotionForge)

Write ONE scene as plain JavaScript: top-level function declarations only, one of which is
`function scene(ctx, t) { ... }` where `t` is seconds since the scene started. Helper functions
are allowed. No classes, no `this`, no `new` (except `new Path2D()`), no globals other than the
names below, no DOM, no timers, no network. Draw in world coordinates (1080×1920). The engine
applies grading, grain, vignette, captions and transitions; do not draw subtitles.

## Maths & timing
- `clamp(v, a=0, b=1)`, `lerp(a, b, k)`, `seg(t, a, b)` → 0..1 progress of t between a and b
- `E.io / E.o / E.i / E.ob / E.anti / E.heavy / E.lin` easing (k 0..1). Prefer `E.heavy`, `E.io`.
- `noise(x, seed)` smooth noise −1..1
- `capLocal(sceneIndex, 'word', fallback)` scene-local time a word is spoken (use `SCENE_INDEX`)
- `STORY.starts`, `VO_ENV` (per-frame voice loudness 0..1 at 30 fps; may be undefined)

## Camera (call first in every scene)
- `cam(ctx, {z, cx, cy, rot, sx, sy})` — z zoom, (cx, cy) world point at screen centre
- `camKeys(t, [[time, {z, cx, cy, rot}], ...], E.heavy)` → camera object
- `handheld(t, amount=6, seed)` → offsets; `shk(t, t0, amt=18, dur=0.35)` → shake offsets
- `camMix(camKeysResult, handheld(...), shk(...))` → merged camera for `cam()`
- Every shot must move a little (slow push-in/drift at least).

## Depth & environments
- `layers(ctx, t, {bg: c => ..., mid: c => ..., fg: c => ...}, {bgBlur, fgBlur, midBlur})` parallax
  (bg moves ×0.4, fg ×1.4, fg blurred). `rackFocus(k)` returns blur options for a focus pull.
- Backgrounds `(ctx, t, opts)`: `hallway({flicker})`, `classroomWall`, `floorWood`, `officeBg`,
  `serverRoom`, `street({night})`, `kitchen`, `bedroom({night})`, `park`, `shop`, or
  `BACKGROUNDS.hallway|classroom|office|serverRoom|street|streetNight|kitchen|bedroom|bedroomNight|park|shop`.
- Foreground: `fgSilhouette(ctx, 'doorframe'|'plant'|'desk'|'locker'|'shoulder', 'left'|'right')`
- Atmosphere: `dust(ctx, t, amt)`, `rain(ctx, t, amt)`, `fog(ctx, t, amt)`, `rays(ctx, t, x, y)`,
  `bokeh(ctx, t)`, `flicker(t, seed)` → 0.35..1 for practical lights.
- Props: `desk(ctx, x, y, s)`, `plant(ctx, x, y, s)`, `doorDraw(ctx, x, y, s, open01)`,
  `phoneDraw(ctx, x, y, s, {screen, glow, rot, content: c => ...})`, `paperDraw(ctx, x, y, s, {rot, mark, markCol})`,
  `wifiIcon(ctx, x, y, s, col, bars)`, `coinDraw`, `billDraw(ctx, x, y, s, rot)`, `spinner(ctx, x, y, t, s)`
- Shapes: `rr(ctx, x, y, w, h, r)` path, `shape(ctx, fill, stroke, lw)`, `line(ctx, [[x,y],...], lw, col)`,
  `txt(ctx, s, x, y, size, fill, {stroke, sw, align, maxW})`, `fillAll(ctx, color)`, `shade(hex, ±amt)`. `OW` = 9.

## Characters (local units: head centre (0,0), shoulders ±46,140, hips ±26,250, feet ≈ ±32,352)
- Kiki — ALWAYS `drawChar(ctx, kiki({x, y, s, face, arms, legs, headRot, headDY, sq, back, flip, handItem}))`.
  Never redraw or restyle him (backwards red cap brim left, freckles, yellow tee). (x, y) is the head centre;
  standing on the floor ≈ y 1000 at s 1.
- Kids: `drawChar(ctx, {x, y, s, shirt, hair: 'bob'|'pony'|'buns'|'long'|'side'|'spiky'|'curly'|'beanie', hairCol, face, arms})`
- Adults in suits: `drawSuit(ctx, {x, y, s, suit, tie, hair: 'karl'|'grey'|'greycap'|'bunGrey', glasses, mustache, face, arms, legs, flip})`
  (`legs` = `{L, R}` polylines from the hips (±30, 330) to the feet (floor ≈ 500), so suits can walk too)
  (Principal Karl = defaults; stands at y ≈ 800).
- `face`: `{lid 0..1, look:[dx,dy] −1..1, brows:'angry'|'sneaky'|'raise'|'up'|'worried'|'flat'|'stern', browLift, mouth:'happy'|'smirk'|'flat'|'teeth'|'O'|'talk'|'sad'|'wavy'|'chew'|'grin', ma 0..1, blink 0..1, red}`
- `arms`: `ARMS.rest|up|cheer|fold|point|think|hips|shrug|phone` or `{L:[[ex,ey],[hx,hy]], R:[...], frontL, frontR, fistL, fistR}`
- Motion: `alive(t, seed)` → `{blink, bob, look, headRot}` (merge into every character so nobody freezes);
  `walk(t, speed)` / `run(t)` → `{legs, arms, bob}`; `sq` squash (+) / stretch (−);
  `ots(ctx, 'kiki'|'suit'|'kid', 'left'|'right')` dark over-the-shoulder foreground (screen space).
- End card: `endCard(ctx, t, {advice, accent, bg, dur})`.

## Direction rules (house tone: serious, cinematic)
Grounded, restrained acting (narrowed eyes, held stares, slow head turns: eyes move first, then the head).
Ease every move. Anticipation before big moves. Characters walk with `walk()`, never slide. No confetti,
hearts, puffs, sparkles, speed lines, sweat gags or bouncy motion. Muted colours with one accent.
Keep faces inside the safe zone x 60–940, y 220–1500 after the camera transform.
