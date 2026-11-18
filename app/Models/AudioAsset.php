<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AudioAsset extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'type', 'path', 'duration', 'loudness_lufs', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'duration' => 'float', 'loudness_lufs' => 'float'];
    }
}
