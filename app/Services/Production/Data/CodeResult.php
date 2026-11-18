<?php

namespace App\Services\Production\Data;

final class CodeResult
{
    public function __construct(
        public readonly string $code,
        public readonly array $validation,
        public readonly string $origin,
        public readonly ?string $aiRequestId = null,
        public readonly int $attempts = 1,
    ) {}
}
