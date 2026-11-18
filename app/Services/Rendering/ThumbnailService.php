<?php

namespace App\Services\Rendering;

use App\Models\Project;
use App\Models\Thumbnail;
use App\Services\Media\Ffmpeg;
use Illuminate\Support\Facades\Storage;

/**
 * Designed 9:16 cover: Kiki large with an intense, serious expression, the rival or a key prop,
 * a dark high-contrast background with one light beam, and a 2–5 word title in Poppins-Bold
 * (white, thick black stroke, one red keyword). Also writes 270x480 and 3:4 crop checks.
 */
class ThumbnailService
{
    public function __construct(private KikitoonsEngine $engine, private Ffmpeg $ffmpeg) {}

    public function make(Project $project, string $title, ?string $keyword): Thumbnail
    {
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
