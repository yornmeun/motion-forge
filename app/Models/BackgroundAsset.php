<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackgroundAsset extends Model
{
    protected $fillable = ['slug', 'name', 'engine_function', 'description', 'default_look', 'ambience', 'is_builtin'];
}
