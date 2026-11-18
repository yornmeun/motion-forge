<?php

namespace App\Models;

use App\Enums\ProductionMode;
use App\Enums\ProductionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'title', 'description', 'genre', 'production_mode', 'workflow', 'aspect_ratio', 'width', 'height', 'frame_rate', 'target_duration_seconds', 'status', 'generation_strategy', 'ai_credential_id', 'ai_model', 'video_credential_id', 'video_model', 'fallback_enabled', 'fallback_credential_id', 'fallback_model', 'budget_limit_usd', 'approved_overrun_usd', 'settings'];

    protected function casts(): array
    {
        return [
            'production_mode' => ProductionMode::class, 'status' => ProductionStatus::class, 'settings' => 'array',
            'fallback_enabled' => 'boolean', 'budget_limit_usd' => 'decimal:4', 'approved_overrun_usd' => 'decimal:4',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function members(): BelongsToMany { return $this->belongsToMany(User::class, 'project_members')->withPivot('role')->withTimestamps(); }
    public function credential(): BelongsTo { return $this->belongsTo(AiCredential::class, 'ai_credential_id'); }
    public function videoCredential(): BelongsTo { return $this->belongsTo(AiCredential::class, 'video_credential_id'); }
    public function fallbackCredential(): BelongsTo { return $this->belongsTo(AiCredential::class, 'fallback_credential_id'); }
    public function packages(): HasMany { return $this->hasMany(ProductionPackage::class)->orderByDesc('version'); }
    public function approvedPackage(): HasOne { return $this->hasOne(ProductionPackage::class)->where('status', 'approved')->latestOfMany('version'); }
    public function latestPackage(): HasOne { return $this->hasOne(ProductionPackage::class)->latestOfMany('version'); }
    public function files(): HasMany { return $this->hasMany(ProjectFile::class); }
    public function scenes(): HasMany { return $this->hasMany(Scene::class)->orderBy('sequence'); }
    public function generationJobs(): HasMany { return $this->hasMany(GenerationJob::class)->latest(); }
    public function renderJobs(): HasMany { return $this->hasMany(RenderJob::class)->latest(); }
    public function artifacts(): HasMany { return $this->hasMany(RenderArtifact::class); }
    public function qualityChecks(): HasMany { return $this->hasMany(QualityCheck::class); }
    public function thumbnails(): HasMany { return $this->hasMany(Thumbnail::class)->latest(); }
    public function captions(): HasMany { return $this->hasMany(Caption::class)->latest(); }
    public function aiRequests(): HasMany { return $this->hasMany(AiRequest::class); }
    public function activity(): HasMany { return $this->hasMany(ProjectActivityLog::class)->latest('created_at'); }
    public function subtitleTracks(): HasMany { return $this->hasMany(SubtitleTrack::class); }
    public function audioAssets(): HasMany { return $this->hasMany(AudioAsset::class); }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function finalVideo(): ?RenderArtifact
    {
        return $this->artifacts()->where('type', 'final_video')->where('is_delivery', true)->latest()->first();
    }

    public function spentUsd(): float
    {
        return (float) $this->aiRequests()->whereIn('status', ['succeeded', 'failed', 'refused'])->sum('estimated_cost_usd');
    }

    /** Storage-relative working directory for this project (private disk). */
    public function storageDir(string $sub = ''): string
    {
        return trim('projects/'.$this->id.'/'.$sub, '/');
    }

    public function log(string $action, string $description, array $properties = [], ?User $user = null): void
    {
        $this->activity()->create([
            'user_id' => $user?->id ?? auth()->id(), 'action' => $action, 'description' => $description,
            'properties' => $properties ?: null, 'ip' => request()?->ip(), 'created_at' => now(),
        ]);
    }
}
