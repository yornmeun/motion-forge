<?php

namespace App\Services\AI\Providers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Contracts\AIProviderInterface;
use App\Exceptions\AIProviderException;
use App\Services\AI\Data\AIResult;
use Closure;

/**
 * Anthropic Claude through the official PHP SDK (anthropic-ai/sdk).
 * Structured output uses output_config.format = json_schema.
 * Server-side model fallbacks are deliberately NOT enabled: MotionForge only falls back when the
 * user configured it, and a fallback to another model changes cost.
 */
class ClaudeProvider implements AIProviderInterface
{
    /** @var Closure(string): Client */
    private Closure $factory;

    public function __construct(?Closure $factory = null)
    {
        $this->factory = $factory ?? fn (string $key) => new Client(apiKey: $key, baseUrl: config('motionforge.providers.claude.base_url'));
    }

    public function slug(): string
    {
        return 'claude';
    }

    public function testConnection(string $apiKey): array
    {
        $models = $this->listModels($apiKey);

        return ['ok' => true, 'message' => 'Authenticated with the Models API ('.count($models).' models visible).', 'models' => $models];
    }

    public function listModels(string $apiKey): array
    {
        return $this->guard($apiKey, function (Client $c) {
            $page = $c->models->list(limit: 100, requestOptions: ['timeout' => 20, 'maxRetries' => 0]);

            return collect($page->getItems())->map(fn ($m) => ['id' => $m->id, 'name' => $m->displayName ?? $m->id])->values()->all();
        });
    }

    public function generateText(string $apiKey, string $model, array $messages, array $options = []): AIResult
    {
        return $this->guard($apiKey, function (Client $c) use ($model, $messages, $options) {
            $msg = $c->messages->create(
                maxTokens: $options['max_tokens'] ?? 16000,
                messages: $this->messages($messages),
                model: $model,
                system: $messages['system'] ?? null,
                outputConfig: $options['output_config'] ?? null,
                requestOptions: ['timeout' => (float) ($options['timeout'] ?? config('motionforge.ai.timeout', 180)), 'maxRetries' => 0],
            );
            if ($msg->stopReason === 'refusal') {
                throw new AIProviderException('Claude declined this request'.($msg->stopDetails?->category ? " ({$msg->stopDetails->category})" : '').'.', 'refusal');
            }
            $text = '';
            foreach ($msg->content as $block) {
                if ($block->type === 'text') {
                    $text .= $block->text;
                }
            }
            if ($msg->stopReason === 'max_tokens') {
                throw new AIProviderException('The response hit the max_tokens limit before finishing.', 'invalid_output');
            }

            return new AIResult($text, null, $msg->usage->inputTokens, $msg->usage->outputTokens, $msg->id, $msg->stopReason);
        });
    }

    public function generateStructured(string $apiKey, string $model, array $messages, array $schema, string $schemaName = 'motionforge_result', array $options = []): AIResult
    {
        $result = $this->generateText($apiKey, $model, $messages, $options + [
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema]],
        ]);
        $json = json_decode(trim($result->text), true);
        if (! is_array($json)) {
            throw new AIProviderException('Claude did not return valid JSON for the requested schema.', 'invalid_output');
        }

        return $result->withJson($json);
    }

    private function messages(array $m): array
    {
        return array_map(fn ($x) => ['role' => $x['role'], 'content' => $x['content']], $m['messages'] ?? []);
    }

    private function guard(string $apiKey, callable $fn): mixed
    {
        try {
            return $fn(($this->factory)($apiKey));
        } catch (AIProviderException $e) {
            throw $e;
        } catch (AuthenticationException|PermissionDeniedException $e) {
            throw new AIProviderException('Anthropic rejected the API key: '.AIProviderException::scrub($e->getMessage(), $apiKey), 'auth', $e->status);
        } catch (RateLimitException $e) {
            throw new AIProviderException('Anthropic rate limit reached. Try again shortly.', 'rate_limit', 429);
        } catch (APITimeoutException|APIConnectionException $e) {
            throw new AIProviderException('Could not reach Anthropic: '.AIProviderException::scrub($e->getMessage(), $apiKey), 'network');
        } catch (APIStatusException $e) {
            $kind = ($e->status ?? 500) >= 500 ? 'server' : 'invalid_request';
            throw new AIProviderException('Anthropic error '.($e->status ?? '').': '.AIProviderException::scrub($e->getMessage(), $apiKey), $kind, $e->status);
        } catch (APIException $e) {
            throw new AIProviderException('Anthropic error: '.AIProviderException::scrub($e->getMessage(), $apiKey), 'server');
        }
    }
}
