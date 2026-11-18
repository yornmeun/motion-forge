<?php

namespace App\Services\AI\Providers;

use App\Contracts\AIProviderInterface;
use App\Contracts\VideoGeneratorInterface;
use App\Exceptions\AIProviderException;
use App\Services\AI\Data\AIResult;
use App\Services\AI\Data\VideoJobStatus;

/**
 * Google Gemini via the Interactions API (POST /v1beta/interactions), the documented default.
 *  - text / structured: response_format {type: text, mime_type: application/json, schema}
 *  - native video (gemini-omni-1.2-flash): response_format {type: video, aspect_ratio, resolution, duration},
 *    background = true, polled with GET /interactions/{id}, cancelled with POST /interactions/{id}/cancel.
 *    The interaction does not report a progress percentage, so none is invented.
 */
class GeminiProvider implements AIProviderInterface, VideoGeneratorInterface
{
    use HttpErrors;

    public function slug(): string
    {
        return 'gemini';
    }

    private function client(string $key, int $timeout = 0)
    {
        return $this->http(config('motionforge.providers.gemini.base_url'), ['x-goog-api-key' => $key], $timeout);
    }

    public function testConnection(string $apiKey): array
    {
        $models = $this->listModels($apiKey);

        return ['ok' => true, 'message' => 'Authenticated with GET /v1beta/models ('.count($models).' models visible).', 'models' => $models];
    }

    public function listModels(string $apiKey): array
    {
        $r = $this->send(fn () => $this->client($apiKey, 20)->get('/models', ['pageSize' => 200]), $apiKey);

        return collect($r->json('models', []))->map(fn ($m) => ['id' => str_replace('models/', '', $m['name']), 'name' => $m['displayName'] ?? $m['name']])->values()->all();
    }

    public function generateText(string $apiKey, string $model, array $messages, array $options = []): AIResult
    {
        $body = array_filter([
            'model' => $model,
            'input' => $this->flatten($messages),
            'system_instruction' => $messages['system'] ?? null,
            'response_format' => $options['response_format'] ?? null,
            'store' => false,
        ], fn ($v) => $v !== null);
        $r = $this->send(fn () => $this->client($apiKey, (int) ($options['timeout'] ?? 0))->post('/interactions', $body), $apiKey);
        $status = $r->json('status');
        if ($status === 'failed' || $status === 'cancelled') {
            throw new AIProviderException("Gemini interaction {$status}.", 'server');
        }
        if ($status === 'incomplete') {
            throw new AIProviderException('Gemini returned an incomplete result (output limit reached).', 'invalid_output');
        }

        return new AIResult($this->text($r->json('steps', [])), null, $r->json('usage.total_input_tokens'), $r->json('usage.total_output_tokens'), $r->json('id'), $status);
    }

    public function generateStructured(string $apiKey, string $model, array $messages, array $schema, string $schemaName = 'motionforge_result', array $options = []): AIResult
    {
        $result = $this->generateText($apiKey, $model, $messages, $options + [
            'response_format' => ['type' => 'text', 'mime_type' => 'application/json', 'schema' => $schema],
        ]);

        return $result->withJson($this->decodeJson($result->text));
    }

    public function submitVideo(string $apiKey, string $model, array $request): VideoJobStatus
    {
        $input = [['type' => 'text', 'text' => $request['prompt']]];
        foreach (array_slice($request['reference_images'] ?? [], 0, 6) as $img) {
            $input[] = ['type' => 'image', 'mime_type' => $img['mime'], 'data' => $img['data']];
        }
        $body = [
            'model' => $model,
            'input' => $input,
            'response_format' => array_filter([
                'type' => 'video',
                'aspect_ratio' => $request['aspect_ratio'],
                'resolution' => $request['resolution'] ?? '720p',
                'duration' => max(3, min(15, (int) $request['duration'])).'s',
            ]),
            'background' => true,
            'store' => true,
        ];
        $r = $this->send(fn () => $this->client($apiKey, 60)->post('/interactions', $body), $apiKey);

        return $this->videoStatus($r->json());
    }

    public function pollVideo(string $apiKey, string $model, string $operationId): VideoJobStatus
    {
        $this->assertId($operationId);
        $r = $this->send(fn () => $this->client($apiKey, 120)->get('/interactions/'.$operationId), $apiKey);

        return $this->videoStatus($r->json());
    }

    public function cancelVideo(string $apiKey, string $operationId): bool
    {
        $this->assertId($operationId);
        $this->send(fn () => $this->client($apiKey, 20)->post('/interactions/'.$operationId.'/cancel'), $apiKey);

        return true;
    }

    private function videoStatus(array $j): VideoJobStatus
    {
        $state = match ($j['status'] ?? 'in_progress') {
            'completed' => 'completed',
            'failed', 'incomplete' => 'failed',
            'cancelled' => 'cancelled',
            default => 'running',
        };
        $data = null;
        $mime = null;
        foreach ($j['steps'] ?? [] as $step) {
            foreach ($step['content'] ?? [] as $part) {
                if (($part['type'] ?? '') === 'video' && ! empty($part['data'])) {
                    $data = $part['data'];
                    $mime = $part['mime_type'] ?? 'video/mp4';
                }
            }
        }
        if ($state === 'completed' && ! $data) {
            $state = 'failed';
        }

        return new VideoJobStatus((string) ($j['id'] ?? ''), $state, null, $data, $mime, $state === 'failed' ? ($j['error']['message'] ?? 'The interaction finished without a video.') : null, $j['usage']['total_output_tokens'] ?? null, $j['usage']['total_input_tokens'] ?? null);
    }

    private function assertId(string $id): void
    {
        if (! preg_match('/^[A-Za-z0-9_\-]{8,300}$/', $id)) {
            throw new AIProviderException('Invalid interaction id.', 'invalid_request');
        }
    }

    private function flatten(array $messages): string
    {
        $list = $messages['messages'] ?? [];
        if (count($list) === 1) {
            return (string) $list[0]['content'];
        }

        return collect($list)->map(fn ($m) => strtoupper($m['role']).":\n".$m['content'])->implode("\n\n");
    }

    private function text(array $steps): string
    {
        $text = '';
        foreach ($steps as $step) {
            if (($step['type'] ?? '') !== 'model_output') {
                continue;
            }
            foreach ($step['content'] ?? [] as $part) {
                if (($part['type'] ?? '') === 'text') {
                    $text .= $part['text'];
                }
            }
        }

        return $text;
    }
}
