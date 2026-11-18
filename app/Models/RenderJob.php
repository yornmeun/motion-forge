<?php

namespace App\Models;

use App\Enums\JobStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RenderJob extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'user_id', 'type', 'status', 'stage', 'progress_current', 'progress_total', 'scene_ids', 'idempotency_key', 'error', 'log', 'started_at', 'finished_at', 'cancel_requested_at'];

    protected function casts(): array
    {
        return ['status' => JobStatus::class, 'scene_ids' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'cancel_requested_at' => 'datetime'];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function artifacts(): HasMany { return $this->hasMany(RenderArtifact::class); }

    public function appendLog(string $line): void
    {
        $this->log = trim(($this->log ?? '')."\n[".now()->format('H:i:s').'] '.$line);
        $this->save();
    }

    public function cancelRequested(): bool
    {
        return $this->fresh()?->cancel_requested_at !== null;
    }
}
