<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksGenerationJob;
use App\Models\AiCredential;
use App\Models\AiRequest;
use App\Models\SceneAsset;
use App\Models\User;
use App\Services\AI\AIManager;
use App\Services\AI\CostEstimator;
use App\Services\AI\ModelRegistry;
use App\Services\AI\UsageTracker;
use App\Services\Media\Ffmpeg;
use App\Services\Production\ProductionWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Polls an asynchronous video generation until done (max ~40 minutes), then stores the clip for approval. */
class PollVideoClipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksGenerationJob;

    public int $tries = 1;

    public int $timeout = 300;

    public const MAX_POLLS = 120;

    public function __construct(public string $assetId, public ?string $generationJobId = null, public int $poll = 1) {}

    public function handle(AIManager $ai, CostEstimator $costs, ProductionWorkflow $workflow): void
    {
        $asset = SceneAsset::with('scene.project')->findOrFail($this->assetId);
        $req = AiRequest::with('provider')->find($asset->metadata['ai_request_id'] ?? null);
        $record = $this->record();
        if ($record?->cancel_requested_at) {
            try {
                $ai->videoProvider($req->provider->slug)->cancelVideo((string) AiCredential::find($req->ai_credential_id)?->apiKey(), $asset->metadata['operation']);
            } catch (Throwable) {
            }
            $asset->update(['status' => 'rejected']);
            $req?->update(['status' => 'failed', 'error_message' => 'Cancelled by user']);
            $this->begin('Cancelled');

            return;
        }
        try {
            $credential = AiCredential::findOrFail($req->ai_credential_id);
            $status = $ai->videoProvider($req->provider->slug)->pollVideo($credential->apiKey(), $req->model, $asset->metadata['operation']);
            if (! $status->isDone()) {
                if ($this->poll >= self::MAX_POLLS) {
                    throw new \RuntimeException('The provider did not finish within the polling window.');
                }
                $this->stage('Provider is generating the clip (poll '.$this->poll.')');
                self::dispatch($this->assetId, $this->generationJobId, $this->poll + 1)->delay(now()->addSeconds(20));

                return;
            }
            if ($status->state !== 'completed') {
                throw new \RuntimeException('Video generation '.$status->state.': '.($status->error ?? 'no details'));
            }
            $bytes = base64_decode($status->videoBase64, true);
            if ($bytes === false || strlen($bytes) < 1000) {
                throw new \RuntimeException('The provider returned no usable video data.');
            }
            Storage::disk(config('motionforge.disk'))->put($asset->path, $bytes);
            $probe = app(Ffmpeg::class)->probe(Storage::disk(config('motionforge.disk'))->path($asset->path));
            $model = app(ModelRegistry::class)->find($req->provider->slug, $req->model);
            $req->update(['status' => 'succeeded', 'output_tokens' => $status->outputTokens, 'input_tokens' => $status->inputTokens,
                'estimated_cost_usd' => $status->outputTokens !== null && $model?->output_price_per_mtok !== null ? $costs->tokens($model, $status->inputTokens, $status->outputTokens) : $req->estimated_cost_usd,
                'media_units' => round($probe['duration'], 3), 'duration_ms' => (int) $req->created_at->diffInMilliseconds(now())]);
            app(UsageTracker::class)->record($req->fresh('provider'));
            $autoRender = (bool) ($record?->payload['auto_render'] ?? false);
            $asset->update(['status' => $autoRender ? 'approved' : 'ready', 'approved_at' => $autoRender ? now() : null, 'metadata' => $asset->metadata + ['duration_actual' => $probe['duration']]]);
            $asset->scene->update(['status' => 'generated', 'error' => null]);
            $this->begin('Clip ready');
            $this->succeed(['asset' => $asset->id], 'Clip ready for review');
            if ($autoRender && $record?->user_id) {
                $user = User::find($record->user_id);
                if ($user) {
                    $workflow->startRenderWhenReady($asset->scene->project->fresh(), $user);
                }
            }
        } catch (Throwable $e) {
            $asset->update(['status' => 'failed', 'metadata' => $asset->metadata + ['error' => mb_substr($e->getMessage(), 0, 500)]]);
            $req?->update(['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 500)]);
            $asset->scene->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
            $this->fail($e);
        }
    }
}
