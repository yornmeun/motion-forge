<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SceneVersion extends Model
{
    use HasUuids;

    protected $fillable = ['scene_id', 'version', 'plan', 'code', 'code_validation', 'origin', 'ai_request_id', 'created_by', 'notes'];

    protected function casts(): array
    {
        return ['plan' => 'array', 'code_validation' => 'array'];
    }

    public function scene(): BelongsTo { return $this->belongsTo(Scene::class); }
    public function aiRequest(): BelongsTo { return $this->belongsTo(AiRequest::class); }

    public function codeIsValid(): bool
    {
        return $this->code !== null && ($this->code_validation['ok'] ?? false) === true;
    }
}
