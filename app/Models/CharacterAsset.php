<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterAsset extends Model
{
    protected $fillable = ['character_id', 'project_file_id', 'type', 'path'];
}
