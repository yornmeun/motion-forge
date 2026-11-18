<?php

namespace App\Jobs;

use App\Contracts\AnimationEngineInterface;
use App\Jobs\Concerns\TracksGenerationJob;
use App\Models\Scene;
use App\Models\SceneAsset;
use App\Services\Rendering\KikitoonsEngine;
use App\Services\Rendering\VideoAssembler;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/** Renders one scene segment (Canvas) or normalises its approved AI clip. Completed segments are cached by content hash. */
class RenderSceneJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksGenerationJob;

    public int $tries = 2;
    public int $timeout = 1800;

    public function __construct(public string $sceneId, public ?string $generationJobId = null, public ?string $renderJobId = null) {}

    public function handle(AnimationEngineInterface $engine, VideoAssembler $assembler): void
    {
        if ($this->batch()?->cancelled() || ! $this->begin('Rendering frames')) return;
        $scene = Scene::with('project', 'currentVersion')->findOrFail($this->sceneId);
        try {
            $disk = \Storage::disk(config('motionforge.disk'));
            if ($scene->source === 'ai_video') {
                $clip = $scene->approvedClip() ?? throw new RuntimeException("Scene {$scene->sequence} has no approved AI clip.");
                $rel = $scene->project->storageDir('renders/scenes/'.$scene->id.'-clip-'.substr(sha1($clip->id.$scene->start_time.$scene->end_time), 0, 12).'.mp4');
                if (! $disk->exists($rel)) {
                    $this->stage('Normalising AI clip to '.$scene->project->width.'×'.$scene->project->height);
                    $disk->makeDirectory(dirname($rel));
                    $assembler->normaliseClip($scene->project, $disk->path($clip->path), $scene->duration(), $disk->path($rel));
                }
            } else {
                if (! $scene->currentVersion?->codeIsValid()) {
                    throw new RuntimeException("Scene {$scene->sequence} has no validated animation code.");
                }
                $cancelKey = $this->renderJobId ? 'render-cancel:'.$this->renderJobId : null;
                $rel = $engine->renderScene($scene, function (int $frame, int $total) use ($cancelKey) {
                    if ($frame % 30 === 0 || $frame === $total) {
                        $this->stage('Rendering frames', $frame, $total);
                    }
                    if ($cancelKey && Cache::get($cancelKey)) {
                        throw new RuntimeException('Render cancelled.');
                    }
                });
            }
            SceneAsset::updateOrCreate(['scene_id' => $scene->id, 'type' => 'scene_video', 'path' => $rel], ['scene_version_id' => $scene->current_version_id, 'origin' => $scene->source === 'ai_video' ? 'ai_clip:normalised' : 'render:'.app(KikitoonsEngine::class)->engineInfo()['kind'], 'status' => 'ready', 'metadata' => ['start' => $scene->start_time, 'end' => $scene->end_time]]);
            $scene->update(['status' => 'rendered', 'error' => null]);
            $this->succeed(['path' => $rel], 'Scene rendered');
        } catch (Throwable $e) {
            $scene->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            $this->handleError($e);
        }
    }

    /** Latest ready segment for the scene's current content (null if stale or missing). */
    public static function segmentFor(Scene $scene): ?string
    {
        $disk = \Storage::disk(config('motionforge.disk'));
        if ($scene->source === 'ai_video') {
            $clip = $scene->approvedClip();
            $rel = $clip ? $scene->project->storageDir('renders/scenes/'.$scene->id.'-clip-'.substr(sha1($clip->id.$scene->start_time.$scene->end_time), 0, 12).'.mp4') : null;
        } else {
            $engine = app(KikitoonsEngine::class);
            $rel = $scene->project->storageDir('renders/scenes/'.$scene->id.'-'.$engine->segmentHash($scene->project->scenes()->with('currentVersion')->get(), $scene).'.mp4');
        }

        return $rel && $disk->exists($rel) ? $rel : null;
    }
}
