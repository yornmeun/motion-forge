<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SceneAsset extends Model
{
    use HasUuids;

    protected $fillable = ['scene_id', 'scene_version_id', 'type', 'path', 'origin', 'status', 'metadata', 'approved_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'approved_at' => 'datetime'];
    }

    public function scene(): BelongsTo { return $this->belongsTo(Scene::class); }
}
