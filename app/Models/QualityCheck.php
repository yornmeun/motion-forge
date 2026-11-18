<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class QualityCheck extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'render_artifact_id', 'kind', 'check_key', 'status', 'value', 'expected', 'message', 'reviewed_by'];
}
