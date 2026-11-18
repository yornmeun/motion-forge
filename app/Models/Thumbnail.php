<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Thumbnail extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'render_artifact_id', 'title_text', 'path', 'checks'];

    protected function casts(): array
    {
        return ['checks' => 'array'];
    }
}
