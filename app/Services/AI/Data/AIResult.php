<?php

namespace App\Services\AI\Data;

final class AIResult
{
    public function __construct(
        public readonly string $text,
        public readonly ?array $json = null,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?string $providerRequestId = null,
        public readonly ?string $stopReason = null,
        public readonly array $meta = [],
    ) {}

    public function withJson(?array $json): self
    {
        return new self($this->text, $json, $this->inputTokens, $this->outputTokens, $this->providerRequestId, $this->stopReason, $this->meta);
    }
}
