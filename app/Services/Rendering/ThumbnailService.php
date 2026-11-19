<?php

namespace App\Services\Rendering;

use App\Models\Project;
use App\Models\Thumbnail;
use App\Services\Media\Ffmpeg;
use Illuminate\Support\Facades\Storage;

/**
 * Landscape projects use the fixed Kikitoons YouTube brand template (1280x720, see landscape()).
 * Vertical projects keep the designed 9:16 cover: Kiki large with an intense, serious expression, the rival or a key prop,
 * a dark high-contrast background with one light beam, and a 2–5 word title in Poppins-Bold
 * (white, thick black stroke, one red keyword). Also writes 270x480 and 3:4 crop checks.
 */
class ThumbnailService
{
    public function __construct(private KikitoonsEngine $engine, private Ffmpeg $ffmpeg) {}

    public function make(Project $project, string $title, ?string $keyword): Thumbnail
    {
        if ($project->isLandscape()) {
            return $this->landscape($project, $title, $keyword);
        }

        $plan = $project->scenes()->with('currentVersion')->get()->first(fn ($s) => ($s->plan()['slam_word'] ?? null) !== null)?->plan()
            ?? $project->scenes()->with('currentVersion')->first()?->plan() ?? [];
        $rival = collect($plan['characters'] ?? [])->first(fn ($c) => mb_strtolower($c) !== 'kiki');
        $bg = $plan['background'] ?? 'hallway';
        $lines = $this->lines($title);
        $p = json_encode(['lines' => $lines, 'kw' => $keyword ? mb_strtoupper($keyword) : null, 'bg' => $bg, 'rival' => (bool) $rival], JSON_UNESCAPED_UNICODE);
        $story = file_get_contents(base_path('engine/kikitoons-compat/director.js'))."\n".<<<JS
const P = {$p};
function sc0(ctx, t) {
  cam(ctx, { z: 1.0, cy: 960 });
  layers(ctx, t, {
    bg: c => { (BACKGROUNDS[P.bg] || BACKGROUNDS.hallway)(c, t, {}); c.save(); c.setTransform(1,0,0,1,0,0); c.fillStyle = 'rgba(4,4,8,0.55)'; c.fillRect(0,0,W,H); c.restore(); rays(c, t, 980, 0, 'rgba(240,200,140,0.16)', 3); },
    mid: c => {
      if (P.rival) drawSuit(c, { x: 850, y: 700, s: 1.25, face: { brows: 'angry', look: [-0.8, 0.1], mouth: 'flat', lid: 0.2 }, alpha: 0.9 });
      drawChar(c, kiki({ x: 470, y: 1180, s: 2.15, face: { brows: 'stern', look: [0.35, -0.05], lid: 0.32, mouth: 'flat' }, headRot: -0.04 }));
    },
  }, { bgBlur: 4, fgBlur: 0 });
  ctx.save(); ctx.setTransform(1,0,0,1,0,0);
  const size = P.lines.some(l => l.length > 11) ? 140 : 170;
  P.lines.forEach((ln, i) => {
    const y = 330 + i * size * 1.05;
    c2line(ctx, ln, y, size);
  });
  ctx.restore();
}
function c2line(ctx, ln, y, size) {
  ctx.font = '700 ' + size + 'px Poppins, sans-serif'; ctx.textBaseline = 'middle';
  const words = ln.split(' '); const total = ctx.measureText(ln).width; let x = W / 2 - total / 2;
  words.forEach(w => { const ww = ctx.measureText(w + ' ').width; ctx.lineWidth = 20; ctx.strokeStyle = '#000'; ctx.lineJoin = 'round'; ctx.textAlign = 'left'; ctx.strokeText(w, x, y); ctx.fillStyle = P.kw && w.replace(/[^A-Z0-9']/g, '') === P.kw.replace(/[^A-Z0-9']/g, '') ? '#c0201c' : '#ffffff'; ctx.fillText(w, x, y); x += ww; });
}
const STORY = { scenes: [sc0], starts: [0, 1], transitions: [], narr: [], looks: ['tense'], grain: 0.05 };
JS;
        $disk = Storage::disk(config('motionforge.disk'));
        $rel = $project->storageDir('delivery/thumbnail.png');
        $disk->makeDirectory(dirname($rel));
        $abs = $disk->path($rel);
        $this->engine->renderStill($story, 0.5, $abs);
        $small = $disk->path($project->storageDir('delivery/checks/thumb-270x480.png'));
        $crop = $disk->path($project->storageDir('delivery/checks/thumb-3x4.png'));
        @mkdir(dirname($small), 0775, true);
        $this->ffmpeg->mustRun([$this->ffmpeg->bin(), '-y', '-loglevel', 'error', '-i', $abs, '-vf', 'scale=270:480', $small], 60);
        $this->ffmpeg->mustRun([$this->ffmpeg->bin(), '-y', '-loglevel', 'error', '-i', $abs, '-vf', 'crop=1080:1440:0:240', $crop], 60);

        return $project->thumbnails()->create(['title_text' => implode(' ', $lines), 'path' => $rel, 'checks' => ['small' => $project->storageDir('delivery/checks/thumb-270x480.png'), 'crop_3x4' => $project->storageDir('delivery/checks/thumb-3x4.png')]]);
    }

    /**
     * YouTube brand template: designed at 1920x1080, delivered at 1280x720 (under 2 MB). Fixed: Kiki on the
     * right with a deep-red rim light, ALL CAPS title on the left with one red keyword, the KIKITOONS badge
     * and red edge bar, dark beam-lit background, bottom-right corner left empty for YouTube's time badge.
     * Per video: Kiki's expression, the rival character, the location and the title.
     */
    public function landscape(Project $project, string $title, ?string $keyword): Thumbnail
    {
        $plans = $project->scenes()->with('currentVersion')->get()->map(fn ($s) => $s->plan());
        $plan = $plans->first(fn ($p) => ($p['slam_word'] ?? null) !== null) ?? $plans->first(fn ($p) => ($p['kind'] ?? 'story') === 'story') ?? [];
        $rival = $plans->flatMap(fn ($p) => $p['cast'] ?? [])->first(fn ($c) => ($c['kind'] ?? '') !== 'kiki');
        $faces = [
            ['brows' => 'stern', 'lid' => 0.32, 'mouth' => 'flat', 'look' => [-0.35, -0.05]],
            ['brows' => 'up', 'browLift' => 0.4, 'lid' => 0.05, 'mouth' => 'O', 'ma' => 0.25, 'look' => [-0.4, 0]],
            ['brows' => 'angry', 'lid' => 0.38, 'mouth' => 'flat', 'look' => [-0.3, 0.05]],
            ['brows' => 'worried', 'lid' => 0.2, 'mouth' => 'wavy', 'look' => [-0.35, -0.1]],
        ];
        $face = $faces[$project->thumbnails()->count() % count($faces)]; // vary the expression from the last thumbnail
        $p = json_encode([
            'lines' => $this->lines($title), 'kw' => $keyword ? mb_strtoupper($keyword) : null, 'bg' => $plan['background'] ?? 'hallway',
            'face' => $face, 'rival' => $rival ? ['kind' => $rival['kind'], 'def' => $rival['def'] ?? (object) []] : null,
        ], JSON_UNESCAPED_UNICODE);
        $story = file_get_contents(base_path('engine/kikitoons-compat/director.js'))."\n".<<<JS
const P = {$p};
const RED = '#C8102E';
function sc0(ctx, t) {
  cam(ctx, { z: 1.16, cx: 221, cy: 1283 });
  layers(ctx, t, {
    bg: c => { (BACKGROUNDS[P.bg] || BACKGROUNDS.hallway)(c, t, {}); c.save(); c.setTransform(1,0,0,1,0,0); c.fillStyle = 'rgba(4,4,8,0.6)'; c.fillRect(0,0,SW,SH); c.restore(); rays(c, t, 760, 0, 'rgba(240,200,140,0.14)', 3); },
    mid: c => {
      if (P.rival) {
        const r = Object.assign({}, P.rival.def, { x: 70, y: 1010, s: 1.25, alpha: 0.85, face: { brows: 'angry', look: [0.7, 0.1], mouth: 'flat', lid: 0.3 } });
        if (P.rival.kind === 'suit') drawSuit(c, r); else drawChar(c, r);
      }
      // deep-red rim light behind Kiki
      c.save(); const g = c.createRadialGradient(540, 1180, 120, 540, 1180, 520); g.addColorStop(0, 'rgba(200,16,46,0.55)'); g.addColorStop(1, 'rgba(200,16,46,0)'); c.fillStyle = g; c.fillRect(0, 600, 1100, 1200); c.restore();
      // arms crossed: an intense pose that keeps the bottom-right corner (YouTube's time badge) clear
      drawChar(c, kiki({ x: 540, y: 1180, s: 2.15, face: P.face, headRot: -0.05, noLegs: true, arms: { L: [[-75, 205], [45, 215]], R: [[75, 200], [-45, 225]], frontL: true, frontR: true } }));
    },
  }, { bgBlur: 5, fgBlur: 0 });
  ctx.save(); ctx.setTransform(1,0,0,1,0,0);
  const v = ctx.createRadialGradient(SW * 0.55, SH * 0.45, SH * 0.3, SW / 2, SH / 2, SW * 0.75); v.addColorStop(0, 'rgba(0,0,0,0)'); v.addColorStop(1, 'rgba(0,0,0,0.75)'); ctx.fillStyle = v; ctx.fillRect(0, 0, SW, SH);
  ctx.fillStyle = RED; ctx.fillRect(0, 0, 14, SH);
  rr(ctx, 40, 36, 300, 74, 14); ctx.fillStyle = RED; ctx.fill();
  ctx.font = '700 44px Poppins, sans-serif'; ctx.fillStyle = '#ffffff'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillText('KIKITOONS', 190, 75);
  const size = P.lines.some(l => l.length > 11) ? 150 : 180, y0 = SH / 2 - (P.lines.length - 1) * size * 0.55;
  P.lines.forEach((ln, i) => titleLine(ctx, ln, 90, y0 + i * size * 1.1, size));
  ctx.restore();
}
function titleLine(ctx, ln, x, y, size) {
  ctx.font = '700 ' + size + 'px Poppins, sans-serif'; ctx.textBaseline = 'middle'; ctx.textAlign = 'left'; ctx.lineJoin = 'round';
  while (ctx.measureText(ln).width > 910 && size > 90) { size -= 6; ctx.font = '700 ' + size + 'px Poppins, sans-serif'; }
  ln.split(' ').forEach(w => {
    ctx.lineWidth = 18; ctx.strokeStyle = '#000'; ctx.strokeText(w, x, y);
    ctx.fillStyle = P.kw && w.replace(/[^A-Z0-9']/g, '') === P.kw.replace(/[^A-Z0-9']/g, '') ? RED : '#ffffff'; ctx.fillText(w, x, y);
    x += ctx.measureText(w + ' ').width;
  });
}
// 'clean' look: no engine grading/vignette over the brand title and edge bar (the scene draws its own vignette)
const STORY = { scenes: [sc0], starts: [0, 1], transitions: [], narr: [], looks: ['clean'], grain: 0.05 };
JS;
        $disk = Storage::disk(config('motionforge.disk'));
        $full = $disk->path($project->storageDir('delivery/checks/thumb-1920x1080.png'));
        @mkdir(dirname($full), 0775, true);
        $this->engine->renderStill($story, 0.5, $full, null, 1920, 1080);
        $rel = $project->storageDir('delivery/thumbnail.png');
        $abs = $disk->path($rel);
        $this->ffmpeg->mustRun([$this->ffmpeg->bin(), '-y', '-loglevel', 'error', '-i', $full, '-vf', 'scale=1280:720', $abs], 60);
        if (filesize($abs) > 2 * 1024 * 1024) {
            @unlink($abs);
            $rel = $project->storageDir('delivery/thumbnail.jpg');
            $abs = $disk->path($rel);
            $this->ffmpeg->mustRun([$this->ffmpeg->bin(), '-y', '-loglevel', 'error', '-i', $full, '-vf', 'scale=1280:720', '-q:v', '3', $abs], 60);
        }
        $checks = [];
        foreach (['640x360', '168x94'] as $size) {
            $checks[$size] = $project->storageDir("delivery/checks/thumb-{$size}.png");
            $this->ffmpeg->mustRun([$this->ffmpeg->bin(), '-y', '-loglevel', 'error', '-i', $abs, '-vf', 'scale='.str_replace('x', ':', $size), $disk->path($checks[$size])], 60);
        }

        return $project->thumbnails()->create(['title_text' => implode(' ', $this->lines($title)), 'path' => $rel, 'checks' => $checks]);
    }

    /** Max two lines of ALL CAPS text. */
    public function lines(string $title): array
    {
        $words = array_slice(preg_split('/\s+/', mb_strtoupper(trim($title))), 0, 5);
        if (count($words) <= 2 || mb_strlen(implode(' ', $words)) <= 11) {
            return [implode(' ', $words)];
        }
        $half = (int) ceil(count($words) / 2);

        return [implode(' ', array_slice($words, 0, $half)), implode(' ', array_slice($words, $half))];
    }
}
