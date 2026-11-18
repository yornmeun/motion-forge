<?php

namespace App\Models;

use App\Enums\JobStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GenerationJob extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'scene_id', 'user_id', 'type', 'status', 'stage', 'progress_current', 'progress_total', 'idempotency_key', 'attempts', 'error', 'payload', 'result', 'started_at', 'finished_at', 'cancel_requested_at'];

    protected function casts(): array
    {
        return ['status' => JobStatus::class, 'payload' => 'array', 'result' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'cancel_requested_at' => 'datetime'];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function scene(): BelongsTo { return $this->belongsTo(Scene::class); }

    public function cancelRequested(): bool
    {
        return $this->fresh()?->cancel_requested_at !== null;
    }
}
