<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Provider failure with a message that is safe to show and log (credentials scrubbed).
 * kind: auth | rate_limit | invalid_request | server | network | refusal | unsupported | budget | invalid_output
 */
class AIProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $kind = 'server',
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public function retryable(): bool
    {
        return in_array($this->kind, ['rate_limit', 'server', 'network'], true);
    }

    public static function scrub(string $message, ?string $secret = null): string
    {
        if ($secret) {
            $message = str_replace($secret, '[redacted]', $message);
        }
        // provider key shapes
        $message = preg_replace('/(sk-[A-Za-z0-9_\-]{8,}|AIza[0-9A-Za-z_\-]{20,}|sk-ant-[A-Za-z0-9_\-]{8,})/', '[redacted]', $message) ?? $message;

        return mb_substr($message, 0, 600);
    }
}
