<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SubtitleTrack extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'production_package_id', 'type', 'cues', 'srt_text'];

    protected function casts(): array
    {
        return ['cues' => 'array'];
    }
}
