<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksGenerationJob;
use App\Models\Scene;
use App\Models\SceneVersion;
use App\Models\User;
use App\Services\Production\AISceneCodeGenerator;
use App\Services\Production\TemplateSceneDirector;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Generates, validates (static + sandbox probe) and stores a new code version for one scene, then previews it. */
class GenerateSceneCodeJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksGenerationJob;

    public int $tries = 3;
    public int $timeout = 1800;

    public function __construct(public string $sceneId, public int $userId, public ?string $generationJobId = null, public array $options = []) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled() || ! $this->begin('Writing animation code')) return;
        $scene = Scene::with('project', 'currentVersion')->findOrFail($this->sceneId);
        $user = User::findOrFail($this->userId);
        try {
            $scene->update(['status' => 'generating', 'error' => null]);
            $strategy = $this->options['strategy'] ?? $scene->project->generation_strategy;
            $generator = $strategy === 'template' ? app(TemplateSceneDirector::class) : app(AISceneCodeGenerator::class);
            $plan = $scene->currentVersion?->plan ?? [];
            $result = $generator->generate($scene, $plan, $user, $this->options + ['idempotency_key' => 'scene_code:'.$this->generationJobId]);
            $this->stage('Validating generated code');
            $next = (int) $scene->versions()->max('version') + 1;
            $version = SceneVersion::create([
                'scene_id' => $scene->id, 'version' => $next, 'plan' => $plan, 'code' => $result->code,
                'code_validation' => $result->validation, 'origin' => str_starts_with($result->origin, 'ai') ? 'ai' : 'template',
                'ai_request_id' => $result->aiRequestId, 'created_by' => $user->id,
                'notes' => "Code by {$result->origin}".($result->attempts > 1 ? " after {$result->attempts} attempts" : '').(! empty($this->options['direction']) ? ' · note: '.mb_substr($this->options['direction'], 0, 120) : ''),
            ]);
            if (! ($result->validation['ok'] ?? false)) {
                $msg = 'Generated code failed validation: '.implode('; ', array_slice($result->validation['errors'] ?? [], 0, 3));
                $scene->update(['status' => 'failed', 'error' => $msg]);
                $this->succeed(['version' => $next, 'valid' => false], 'Code invalid — kept previous version');
                $scene->project->log('scene.code_invalid', "Scene {$scene->sequence}: {$msg}");

                return;
            }
            $scene->update(['current_version_id' => $version->id, 'status' => 'generated', 'error' => null]);
            $this->stage('Rendering preview frame');
            GenerateScenePreviewJob::dispatchSync($scene->id);
            $this->succeed(['version' => $next, 'valid' => true, 'origin' => $result->origin], 'Scene ready for review');
        } catch (Throwable $e) {
            $scene->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            $this->handleError($e);
        }
    }
}
