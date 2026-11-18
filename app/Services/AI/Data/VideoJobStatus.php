<?php

namespace App\Services\AI\Data;

final class VideoJobStatus
{
    /**
     * @param string $state running | completed | failed | cancelled
     * @param int|null $progress provider-reported percentage; null when the provider does not report one
     */
    public function __construct(
        public readonly string $operationId,
        public readonly string $state,
        public readonly ?int $progress = null,
        public readonly ?string $videoBase64 = null,
        public readonly ?string $mime = null,
        public readonly ?string $error = null,
        public readonly ?int $outputTokens = null,
        public readonly ?int $inputTokens = null,
    ) {}

    public function isDone(): bool
    {
        return in_array($this->state, ['completed', 'failed', 'cancelled'], true);
    }
}

