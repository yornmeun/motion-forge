<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RenderArtifact extends Model
{
    use HasUuids;

    protected $fillable = ['render_job_id', 'project_id', 'scene_id', 'type', 'path', 'size', 'sha256', 'metadata', 'is_delivery'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'is_delivery' => 'boolean'];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
}
