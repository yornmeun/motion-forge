<?php

namespace App\Jobs;

use App\Contracts\AnimationEngineInterface;
use App\Models\Scene;
use App\Models\SceneAsset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateScenePreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public string $sceneId, public ?float $at = null) {}

    public function handle(AnimationEngineInterface $engine): void
    {
        $scene = Scene::with('currentVersion', 'project')->findOrFail($this->sceneId);
        if (! $scene->currentVersion?->codeIsValid()) {
            return;
        }
        try {
            $path = $engine->generatePreview($scene, $this->at);
            SceneAsset::updateOrCreate(['scene_id' => $scene->id, 'scene_version_id' => $scene->current_version_id, 'type' => 'preview_png'], ['path' => $path, 'origin' => 'render:'.$engine->engineInfo()['kind'], 'status' => 'ready']);
            $scene->update(['status' => 'preview_ready', 'error' => null]);
        } catch (Throwable $e) {
            $scene->update(['status' => 'failed', 'error' => 'Preview failed: '.mb_substr($e->getMessage(), 0, 800)]);
        }
    }
}
