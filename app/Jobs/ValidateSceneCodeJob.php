<?php

namespace App\Jobs;

use App\Contracts\AnimationEngineInterface;
use App\Models\SceneVersion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Static + sandbox validation for a manually edited code version. */
class ValidateSceneCodeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $sceneVersionId) {}

    public function handle(AnimationEngineInterface $engine): void
    {
        $v = SceneVersion::with('scene.project')->findOrFail($this->sceneVersionId);
        $res = $engine->validateCode((string) $v->code);
        if ($res['ok']) {
            $probe = $engine->probeCode($v->scene, (string) $v->code);
            if (! $probe['ok']) {
                $res['ok'] = false;
                $res['errors'][] = $probe['message'];
            }
        }
        $v->update(['code_validation' => $res]);
        $scene = $v->scene;
        if ($scene->current_version_id === $v->id) {
            $scene->update(['status' => $res['ok'] ? 'generated' : 'failed', 'error' => $res['ok'] ? null : implode('; ', array_slice($res['errors'], 0, 3))]);
            if ($res['ok']) {
                GenerateScenePreviewJob::dispatchSync($scene->id);
            }
        }
    }
}
