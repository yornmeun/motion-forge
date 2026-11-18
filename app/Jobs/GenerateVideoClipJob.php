<?php

namespace App\Jobs;

use App\Enums\AICapability;
use App\Jobs\Concerns\TracksGenerationJob;
use App\Models\AiRequest;
use App\Models\Scene;
use App\Models\SceneAsset;
use App\Models\User;
use App\Services\AI\AIManager;
use App\Services\AI\BudgetGuard;
use App\Services\AI\CostEstimator;
use App\Services\AI\ProviderRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/** Submits a native video generation (real video endpoint) for one scene and schedules polling. */
class GenerateVideoClipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksGenerationJob;

    public int $tries = 3;
    public int $timeout = 1800;

    public function __construct(public string $sceneId, public int $userId, public ?string $generationJobId = null, public array $options = []) {}

    public function handle(AIManager $ai, ProviderRouter $router, CostEstimator $costs, BudgetGuard $budget): void
    {
        if (! $this->begin('Submitting video generation')) return;
        $scene = Scene::with('project', 'currentVersion')->findOrFail($this->sceneId);
        $user = User::findOrFail($this->userId);
        $project = $scene->project;
        try {
            $route = $router->forProject($project, $user, AICapability::NativeVideo, $this->options['credential_id'] ?? null, $this->options['model'] ?? null);
            $limits = $route->model->limits ?? [];
            $duration = (int) max($limits['min_seconds'] ?? 3, min($limits['max_seconds'] ?? 15, (int) ceil($scene->duration())));
            $aspect = in_array($project->aspect_ratio, $limits['aspect_ratios'] ?? [$project->aspect_ratio], true) ? $project->aspect_ratio : throw new \RuntimeException("{$route->model->model_id} does not support {$project->aspect_ratio}.");
            $plan = $scene->plan();
            $prompt = $this->options['prompt'] ?? $plan['video_prompt'] ?? $plan['visual_description'] ?? '';
            if (mb_strlen(trim($prompt)) < 10) throw new \RuntimeException('The scene needs a video prompt (at least 10 characters).');
            $estimate = $costs->video($route->model, $duration);
            $budget->check($user, $project, $route->credential, $estimate, (bool) ($this->options['approved'] ?? false));
            $refs = [];
            foreach ($project->files()->where('role', 'character_ref')->limit(3)->get() as $f) {
                if (in_array($f->mime, ['image/png', 'image/jpeg', 'image/webp'], true) && $f->size < 7_000_000) $refs[] = ['mime' => $f->mime, 'data' => base64_encode(file_get_contents($f->absolutePath()))];
            }
            $req = AiRequest::create(['user_id' => $user->id, 'project_id' => $project->id, 'scene_id' => $scene->id, 'ai_credential_id' => $route->credential->id, 'ai_provider_id' => $route->credential->ai_provider_id, 'model' => $route->model->model_id, 'capability' => 'native_video', 'request_type' => 'video_clip', 'status' => 'pending', 'media_units' => $duration, 'estimated_cost_usd' => $estimate, 'metadata' => ['route' => $route->label(), 'prompt' => mb_substr($prompt, 0, 2000), 'aspect_ratio' => $aspect]]);
            $status = $ai->videoProvider($route->credential->provider->slug)->submitVideo($route->credential->apiKey(), $route->model->model_id, [
                'prompt' => $prompt, 'aspect_ratio' => $aspect, 'duration' => $duration, 'resolution' => $this->options['resolution'] ?? '720p', 'reference_images' => $refs,
            ]);
            $req->update(['provider_request_id' => $status->operationId]);
            $asset = SceneAsset::create(['scene_id' => $scene->id, 'scene_version_id' => $scene->current_version_id, 'type' => 'ai_video', 'path' => $project->storageDir('clips/'.Str::uuid().'.mp4'), 'origin' => 'ai:'.$route->credential->provider->slug.'/'.$route->model->model_id, 'status' => 'pending', 'metadata' => ['operation' => $status->operationId, 'ai_request_id' => $req->id, 'prompt' => $prompt, 'duration' => $duration]]);
            $scene->update(['status' => 'generating', 'error' => null]);
            $this->stage('Waiting for the provider (no progress percentage reported)');
            PollVideoClipJob::dispatch($asset->id, $this->generationJobId)->delay(now()->addSeconds(20));
        } catch (Throwable $e) {
            $scene->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 800)]);
            $this->handleError($e);
        }
    }
}
