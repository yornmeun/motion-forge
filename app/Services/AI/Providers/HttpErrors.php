<?php

namespace App\Services\AI\Providers;

use App\Exceptions\AIProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Shared HTTP plumbing for REST providers: fixed base URL, timeouts, error mapping, key scrubbing. */
trait HttpErrors
{
    protected function http(string $baseUrl, array $headers, int $timeout = 0): PendingRequest
    {
        return Http::baseUrl($baseUrl)->withHeaders($headers)->acceptJson()->asJson()
            ->timeout($timeout ?: (int) config('motionforge.ai.timeout', 180))->connectTimeout(10)
            ->withOptions(['allow_redirects' => false]);
    }

    protected function send(callable $call, string $apiKey): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new AIProviderException('Could not reach the provider: '.AIProviderException::scrub($e->getMessage(), $apiKey), 'network');
        }
        if ($response->successful()) {
            return $response;
        }
        $status = $response->status();
        $detail = $response->json('error.message') ?? $response->json('message') ?? $response->json('error') ?? $response->body();
        $detail = AIProviderException::scrub(is_string($detail) ? $detail : json_encode($detail), $apiKey);
        $kind = match (true) {
            in_array($status, [401, 403], true) => 'auth',
            $status === 429 => 'rate_limit',
            $status === 402 => 'auth',
            in_array($status, [400, 404, 409, 413, 422], true) => 'invalid_request',
            default => 'server',
        };
        $retryAfter = (int) ($response->header('retry-after') ?: 0) ?: null;

        throw new AIProviderException("Provider returned HTTP {$status}: {$detail}", $kind, $status, $retryAfter);
    }

    protected function decodeJson(string $text): array
    {
        $clean = trim($text);
        if (str_starts_with($clean, '```')) {
            $clean = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $clean);
        }
        $data = json_decode($clean, true);
        if (! is_array($data)) {
            throw new AIProviderException('The model did not return valid JSON.', 'invalid_output');
        }

        return $data;
    }
}
