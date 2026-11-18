<?php

namespace App\Services\AI;

use App\Models\AiModel;

/** Cost estimates from the editable pricing table. Returns null when the model has no verified price. */
class CostEstimator
{
    public function tokens(?AiModel $model, ?int $in, ?int $out): ?float
    {
        if (! $model || ($model->input_price_per_mtok === null && $model->output_price_per_mtok === null)) {
            return null;
        }

        return round(($in ?? 0) / 1e6 * (float) $model->input_price_per_mtok + ($out ?? 0) / 1e6 * (float) $model->output_price_per_mtok, 6);
    }

    public function video(?AiModel $model, float $seconds): ?float
    {
        if (! $model || $model->video_price_per_second === null) {
            return null;
        }

        return round($seconds * (float) $model->video_price_per_second, 6);
    }

    /** Rough pre-call estimate from prompt size (≈4 chars per token) and expected output. */
    public function preflight(?AiModel $model, int $promptChars, int $expectedOutputTokens): ?float
    {
        return $this->tokens($model, (int) ceil($promptChars / 4), $expectedOutputTokens);
    }
}
