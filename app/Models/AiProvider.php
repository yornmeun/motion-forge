<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiProvider extends Model
{
    protected $fillable = ['slug', 'name', 'base_url', 'docs_url', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(AiCredential::class);
    }
}
