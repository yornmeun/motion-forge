<?php

namespace App\Console\Commands;

use App\Jobs\RenderSceneJob;
use App\Models\SceneAsset;
use App\Models\SystemSetting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Housekeeping (scheduled daily in routes/console.php):
 *  - temporary render/extract directories left by crashed or killed workers,
 *  - preview stills of scene versions that are no longer current (the editor re-renders a
 *    preview on demand when an old version is restored),
 *  - cached scene segments that no longer match their scene, after the retention period.
 * Final videos, thumbnails, captions, SRTs and package inputs are never touched.
 */
#[Signature('motionforge:cleanup {--tmp-hours=6 : Age before temporary work directories are removed} {--days= : Retention for stale previews and scene segments (default: setting or config)} {--dry-run : List what would be removed without deleting}')]
#[Description('Remove stale temporary render files, old previews and outdated scene segments')]
class CleanupCommand extends Command
{
    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $days = (int) ($this->option('days') ?? SystemSetting::get('retention.render_cache_days', config('motionforge.retention.render_cache_days', 30)));
        $tmpCutoff = now()->subHours(max(1, (int) $this->option('tmp-hours')))->getTimestamp();
        $cutoff = now()->subDays(max(1, $days));
        $disk = Storage::disk(config('motionforge.disk'));

        // 1. temporary work directories
        $tmp = 0;
        foreach ([storage_path('app/tmp/render'), storage_path('app/tmp')] as $base) {
            if (! is_dir($base)) continue;
            foreach (File::directories($base) as $dir) {
                if ($dir === storage_path('app/tmp/render') || filemtime($dir) > $tmpCutoff) continue;
                $tmp++;
                $dry ? $this->line("tmp: {$dir}") : File::deleteDirectory($dir);
            }
            foreach (File::files($base) as $file) {
                if ($file->getMTime() > $tmpCutoff) continue;
                $tmp++;
                $dry ? $this->line("tmp: {$file->getPathname()}") : File::delete($file->getPathname());
            }
        }

        // 2. previews of versions that are no longer current
        $previews = 0;
        SceneAsset::with('scene')->where('type', 'preview_png')->where('updated_at', '<', $cutoff)->chunkById(200, function ($assets) use (&$previews, $dry, $disk) {
            foreach ($assets as $a) {
                if ($a->scene && $a->scene_version_id === $a->scene->current_version_id) continue;
                $previews++;
                if ($dry) { $this->line("preview: {$a->path}"); continue; }
                if ($a->path) $disk->delete($a->path);
                $a->delete();
            }
        });

        // 3. scene segments that no longer match the scene's current content
        $segments = 0;
        SceneAsset::with('scene.project')->where('type', 'scene_video')->where('updated_at', '<', $cutoff)->chunkById(100, function ($assets) use (&$segments, $dry, $disk) {
            foreach ($assets as $a) {
                if ($a->scene && $a->scene->project && RenderSceneJob::segmentFor($a->scene) === $a->path) continue;
                $segments++;
                if ($dry) { $this->line("segment: {$a->path}"); continue; }
                if ($a->path) $disk->delete($a->path);
                $a->delete();
            }
        });

        $verb = $dry ? 'Would remove' : 'Removed';
        $this->info("{$verb} {$tmp} temporary item(s), {$previews} stale preview(s) and {$segments} outdated scene segment(s) (retention {$days} days).");

        return self::SUCCESS;
    }
}
