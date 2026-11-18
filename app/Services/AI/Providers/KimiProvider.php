<?php

namespace App\Services\AI\Providers;

use App\Contracts\AIProviderInterface;
use App\Exceptions\AIProviderException;
use App\Services\AI\Data\AIResult;

/**
 * Moonshot Kimi: OpenAI-compatible Chat Completions at https://api.moonshot.ai/v1.
 * response_format json_schema is supported (strict defaults to true).
 */
class KimiProvider implements AIProviderInterface
{
    use HttpErrors;

    public function slug(): string
    {
        return 'kimi';
    }

    private function client(string $key, int $timeout = 0)
    {
        return $this->http(config('motionforge.providers.kimi.base_url'), ['Authorization' => 'Bearer '.$key], $timeout);
    }

    public function testConnection(string $apiKey): array
    {
        $models = $this->listModels($apiKey);

        return ['ok' => true, 'message' => 'Authenticated with GET /v1/models ('.count($models).' models visible).', 'models' => $models];
    }

    public function listModels(string $apiKey): array
    {
        $r = $this->send(fn () => $this->client($apiKey, 20)->get('/models'), $apiKey);

        return collect($r->json('data', []))->map(fn ($m) => ['id' => $m['id'], 'name' => $m['id']])->values()->all();
    }

    public function generateText(string $apiKey, string $model, array $messages, array $options = []): AIResult
    {
        $chat = [];
        if (! empty($messages['system'])) {
            $chat[] = ['role' => 'system', 'content' => $messages['system']];
        }
        foreach ($messages['messages'] ?? [] as $m) {
            $chat[] = ['role' => $m['role'], 'content' => $m['content']];
        }
        $body = array_filter(['model' => $model, 'messages' => $chat, 'max_tokens' => $options['max_tokens'] ?? 16000, 'response_format' => $options['response_format'] ?? null], fn ($v) => $v !== null);
        $r = $this->send(fn () => $this->client($apiKey, (int) ($options['timeout'] ?? 0))->post('/chat/completions', $body), $apiKey);
        $choice = $r->json('choices.0');
        if (($choice['finish_reason'] ?? null) === 'length') {
            throw new AIProviderException('The response hit the max_tokens limit before finishing.', 'invalid_output');
        }

        return new AIResult((string) ($choice['message']['content'] ?? ''), null, $r->json('usage.prompt_tokens'), $r->json('usage.completion_tokens'), $r->json('id'), $choice['finish_reason'] ?? null);
    }

    public function generateStructured(string $apiKey, string $model, array $messages, array $schema, string $schemaName = 'motionforge_result', array $options = []): AIResult
    {
        $result = $this->generateText($apiKey, $model, $messages, $options + [
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => $schemaName, 'schema' => $schema, 'strict' => true]],
        ]);

        return $result->withJson($this->decodeJson($result->text));
    }
}
