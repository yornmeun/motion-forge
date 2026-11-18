<?php

namespace App\Models;

use App\Enums\AICapability;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiModel extends Model
{
    protected $fillable = ['ai_provider_id', 'model_id', 'display_name', 'capabilities', 'input_price_per_mtok', 'output_price_per_mtok', 'video_price_per_second', 'pricing_source', 'pricing_verified_at', 'limits', 'is_active', 'is_registry', 'discovered_at'];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array', 'limits' => 'array', 'is_active' => 'boolean', 'is_registry' => 'boolean',
            'discovered_at' => 'datetime', 'pricing_verified_at' => 'date',
            'input_price_per_mtok' => 'decimal:4', 'output_price_per_mtok' => 'decimal:4', 'video_price_per_second' => 'decimal:4',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function supports(AICapability $capability): bool
    {
        return $this->is_active && in_array($capability->value, $this->capabilities ?? [], true);
    }

    public function hasPricing(): bool
    {
        return $this->input_price_per_mtok !== null || $this->video_price_per_second !== null;
    }
}
