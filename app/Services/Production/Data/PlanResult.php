<?php

namespace App\Services\Production\Data;

final class PlanResult
{
    /**
     * @param list<array> $scenes fully-timed scene plans (including the end card)
     * @param string $origin 'ai:<provider>/<model>' | 'template'
     */
    public function __construct(
        public readonly array $scenes,
        public readonly array $meta,
        public readonly string $origin,
        public readonly ?string $aiRequestId = null,
        public readonly array $warnings = [],
    ) {}
}
