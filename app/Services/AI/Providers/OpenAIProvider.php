<?php

namespace App\Services\AI\Providers;

use App\Contracts\AIProviderInterface;
use App\Exceptions\AIProviderException;
use App\Services\AI\Data\AIResult;

/**
 * OpenAI Responses API (POST /v1/responses). Structured output: text.format json_schema (strict).
 * Native video is not offered: the Sora 2 / Videos API was shut down on 2026-09-24.
 */
class OpenAIProvider implements AIProviderInterface
{
    use HttpErrors;

    public function slug(): string
    {
        return 'openai';
    }

    private function client(string $key, int $timeout = 0)
    {
        return $this->http(config('motionforge.providers.openai.base_url'), ['Authorization' => 'Bearer '.$key], $timeout);
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
        $body = array_filter([
            'model' => $model,
            'instructions' => $messages['system'] ?? null,
            'input' => array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']], $messages['messages'] ?? []),
            'max_output_tokens' => $options['max_tokens'] ?? 16000,
            'text' => $options['text'] ?? null,
            'store' => false,
        ], fn ($v) => $v !== null);
        $r = $this->send(fn () => $this->client($apiKey, (int) ($options['timeout'] ?? 0))->post('/responses', $body), $apiKey);

        $text = '';
        foreach ($r->json('output', []) as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }
            foreach ($item['content'] ?? [] as $part) {
                if (($part['type'] ?? '') === 'refusal') {
                    throw new AIProviderException('The model refused: '.mb_substr((string) ($part['refusal'] ?? ''), 0, 300), 'refusal');
                }
                if (($part['type'] ?? '') === 'output_text') {
                    $text .= $part['text'];
                }
            }
        }
        if ($r->json('status') === 'incomplete') {
            throw new AIProviderException('The response was incomplete: '.json_encode($r->json('incomplete_details')), 'invalid_output');
        }

        return new AIResult($text, null, $r->json('usage.input_tokens'), $r->json('usage.output_tokens'), $r->json('id'), $r->json('status'));
    }

    public function generateStructured(string $apiKey, string $model, array $messages, array $schema, string $schemaName = 'motionforge_result', array $options = []): AIResult
    {
        $result = $this->generateText($apiKey, $model, $messages, $options + [
            'text' => ['format' => ['type' => 'json_schema', 'name' => $schemaName, 'schema' => $schema, 'strict' => true]],
        ]);

        return $result->withJson($this->decodeJson($result->text));
    }
}
