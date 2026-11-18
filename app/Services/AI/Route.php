<?php

namespace App\Services\AI;

use App\Contracts\AIProviderInterface;
use App\Models\AiCredential;
use App\Models\AiModel;

/** A resolved (credential, model, provider adapter) triple for one AI operation. */
final class Route
{
    public function __construct(
        public readonly AiCredential $credential,
        public readonly AiModel $model,
        public readonly AIProviderInterface $provider,
        public readonly bool $isFallback = false,
    ) {}

    public function label(): string
    {
        return $this->credential->provider->name.' · '.$this->model->model_id.($this->isFallback ? ' (fallback)' : '');
    }
}
