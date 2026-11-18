<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRequest extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'project_id', 'scene_id', 'ai_credential_id', 'ai_provider_id', 'model', 'capability', 'request_type', 'status', 'idempotency_key', 'input_tokens', 'output_tokens', 'media_units', 'duration_ms', 'estimated_cost_usd', 'confirmed_cost_usd', 'provider_request_id', 'error_message', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'estimated_cost_usd' => 'decimal:6', 'confirmed_cost_usd' => 'decimal:6', 'media_units' => 'float'];
    }

    public function provider(): BelongsTo { return $this->belongsTo(AiProvider::class, 'ai_provider_id'); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function scene(): BelongsTo { return $this->belongsTo(Scene::class); }
}
