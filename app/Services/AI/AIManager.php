<?php

namespace App\Services\AI;

use App\Contracts\AIProviderInterface;
use App\Contracts\VideoGeneratorInterface;
use App\Enums\AICapability;
use App\Exceptions\AIProviderException;
use App\Exceptions\BudgetExceededException;
use App\Models\AiRequest;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Services\AI\Data\AIResult;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Entry point for every AI call. Each call:
 *  1. checks the capability against the registry,
 *  2. enforces budgets (BudgetGuard),
 *  3. records an ai_requests row (tokens, duration, estimated cost, status, sanitised errors),
 *  4. honours idempotency keys: a succeeded request with the same key returns its stored result
 *     instead of paying for the same generation twice.
 * Credentials are read from the credential model only at call time and never logged.
 */
class AIManager
{
    /** @param array<string, AIProviderInterface> $providers */
    public function __construct(private array $providers) {}

    public function provider(string $slug): AIProviderInterface
    {
        return $this->providers[$slug] ?? throw new InvalidArgumentException("Unsupported AI provider [{$slug}].");
    }

    public function videoProvider(string $slug): VideoGeneratorInterface
    {
        $p = $this->provider($slug);
        if (! $p instanceof VideoGeneratorInterface) {
            throw new AIProviderException("{$slug} has no native video generation API in MotionForge.", 'unsupported');
        }

        return $p;
    }

    public function slugs(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Run a text or structured call.
     *
     * @param array{system?: string, messages: array} $messages
     * @param array|null $schema JSON schema for structured output (null = plain text)
     */
    public function call(
        Route $route, User $user, AICapability $capability, string $requestType, array $messages,
        ?array $schema = null, ?Project $project = null, ?Scene $scene = null, ?string $idempotencyKey = null,
        bool $approvedExpensive = false, int $expectedOutputTokens = 4000, array $options = [],
    ): AIResult {
        if (! $route->model->supports($capability)) {
            throw new AIProviderException("{$route->model->model_id} is not registered for {$capability->label()}.", 'unsupported');
        }
        if ($schema !== null && ! $route->model->supports(AICapability::StructuredOutput)) {
            throw new AIProviderException("{$route->model->model_id} is not registered for structured JSON output.", 'unsupported');
        }
        if ($idempotencyKey && ($done = AiRequest::where('idempotency_key', $idempotencyKey)->where('status', 'succeeded')->first())) {
            $out = $done->metadata['output'] ?? null;
            if (is_string($out)) {
                return new AIResult($out, $schema ? json_decode($out, true) : null, $done->input_tokens, $done->output_tokens, $done->provider_request_id, 'cached', ['cached' => true, 'request_id' => $done->id]);
            }
        }

        $estimator = app(CostEstimator::class);
        $promptChars = strlen(json_encode($messages)) + ($schema ? strlen(json_encode($schema)) : 0);
        $preEstimate = $estimator->preflight($route->model, $promptChars, $expectedOutputTokens);

        $request = AiRequest::create([
            'user_id' => $user->id, 'project_id' => $project?->id, 'scene_id' => $scene?->id,
            'ai_credential_id' => $route->credential->id, 'ai_provider_id' => $route->credential->ai_provider_id,
            'model' => $route->model->model_id, 'capability' => $capability->value, 'request_type' => $requestType,
            'status' => 'pending', 'idempotency_key' => $idempotencyKey ? $this->freshKey($idempotencyKey) : null,
            'metadata' => ['route' => $route->label(), 'preflight_estimate_usd' => $preEstimate, 'fallback' => $route->isFallback],
        ]);

        try {
            app(BudgetGuard::class)->check($user, $project, $route->credential, $preEstimate, $approvedExpensive);
        } catch (BudgetExceededException $e) {
            $request->update(['status' => 'blocked_budget', 'error_message' => $e->getMessage()]);
            throw $e;
        }

        $key = $route->credential->apiKey();
        if (! $key) {
            $request->update(['status' => 'failed', 'error_message' => 'Credential has no usable key.']);
            throw new AIProviderException('This connection has no usable API key.', 'auth');
        }

        $started = hrtime(true);
        try {
            $result = $schema === null
                ? $route->provider->generateText($key, $route->model->model_id, $messages, $options)
                : $route->provider->generateStructured($key, $route->model->model_id, $messages, $schema, 'motionforge_'.$requestType, $options);
        } catch (AIProviderException $e) {
            $request->update([
                'status' => $e->kind === 'refusal' ? 'refused' : 'failed',
                'duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
                'error_message' => AIProviderException::scrub($e->getMessage(), $key),
            ]);
            if ($e->kind === 'auth') {
                $route->credential->update(['status' => 'invalid', 'status_message' => AIProviderException::scrub($e->getMessage(), $key)]);
            }
            Log::warning('AI request failed', ['request' => $request->id, 'route' => $route->label(), 'kind' => $e->kind, 'status' => $e->status]);
            throw $e;
        }

        $cost = $estimator->tokens($route->model, $result->inputTokens, $result->outputTokens);
        $request->update([
            'status' => 'succeeded', 'duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
            'input_tokens' => $result->inputTokens, 'output_tokens' => $result->outputTokens,
            'estimated_cost_usd' => $cost, 'provider_request_id' => $result->providerRequestId,
            'metadata' => $request->metadata + ['output' => mb_substr($result->text, 0, 200000)],
        ]);
        app(UsageTracker::class)->record($request->fresh('provider'));

        return new AIResult($result->text, $result->json, $result->inputTokens, $result->outputTokens, $result->providerRequestId, $result->stopReason, ['request_id' => $request->id]);
    }

    /** Failed attempts keep their key suffixed so a retry can reuse the base key. */
    private function freshKey(string $key): string
    {
        AiRequest::where('idempotency_key', $key)->where('status', '!=', 'succeeded')->get()
            ->each(fn (AiRequest $r) => $r->update(['idempotency_key' => $key.':'.$r->id]));

        return $key;
    }
}
