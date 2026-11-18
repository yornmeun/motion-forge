<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scene extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'sequence', 'start_time', 'end_time', 'cue_start', 'cue_end', 'narration_reference', 'kind', 'source', 'status', 'current_version_id', 'ai_credential_id', 'ai_model', 'error'];

    protected function casts(): array
    {
        return ['start_time' => 'float', 'end_time' => 'float'];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function versions(): HasMany { return $this->hasMany(SceneVersion::class)->orderByDesc('version'); }
    public function currentVersion(): BelongsTo { return $this->belongsTo(SceneVersion::class, 'current_version_id'); }
    public function assets(): HasMany { return $this->hasMany(SceneAsset::class)->latest(); }
    public function credential(): BelongsTo { return $this->belongsTo(AiCredential::class, 'ai_credential_id'); }

    public function duration(): float
    {
        return round($this->end_time - $this->start_time, 3);
    }

    public function plan(): array
    {
        return $this->currentVersion?->plan ?? [];
    }

    public function previewAsset(): ?SceneAsset
    {
        return $this->assets()->where('type', 'preview_png')->where('scene_version_id', $this->current_version_id)->first();
    }

    public function approvedClip(): ?SceneAsset
    {
        return $this->assets()->where('type', 'ai_video')->where('status', 'approved')->latest()->first();
    }
}
