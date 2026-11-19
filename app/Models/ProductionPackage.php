<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The approved story package. Once approved, the locked story, narration, SRT and voiceover are
 * immutable: their SHA-256 hashes are recorded and re-verified before every production job.
 */
class ProductionPackage extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'version', 'source', 'status', 'locked_story', 'narration_text', 'srt_text', 'voiceover_file_id', 'notes', 'documents', 'story_hash', 'narration_hash', 'srt_hash', 'voice_hash', 'audio_duration', 'srt_end', 'validation_report', 'approved_at', 'approved_by'];

    protected function casts(): array
    {
        return ['validation_report' => 'array', 'documents' => 'array', 'approved_at' => 'datetime', 'audio_duration' => 'float', 'srt_end' => 'float'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function voiceover(): BelongsTo
    {
        return $this->belongsTo(ProjectFile::class, 'voiceover_file_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved' && $this->approved_at !== null;
    }

    /** A V2 document by role (scene_sheet, character_registry, background_registry, kiki_reference, video_execution, readme). */
    public function document(string $role): ?string
    {
        $text = $this->documents[$role] ?? null;

        return is_string($text) && trim($text) !== '' ? $text : null;
    }

    public function isLocked(): bool
    {
        return in_array($this->status, ['approved', 'superseded'], true);
    }
}
