<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProjectFile extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'user_id', 'role', 'disk', 'path', 'original_name', 'mime', 'size', 'sha256', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }

    public function absolutePath(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }
}
