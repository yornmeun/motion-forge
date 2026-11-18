<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Character extends Model
{
    protected $fillable = ['user_id', 'slug', 'name', 'kind', 'description', 'definition', 'is_builtin'];

    protected function casts(): array
    {
        return ['definition' => 'array', 'is_builtin' => 'boolean'];
    }

    public function assets(): HasMany { return $this->hasMany(CharacterAsset::class); }

    public function scopeVisibleTo($q, User $user)
    {
        return $q->where(fn ($w) => $w->whereNull('user_id')->orWhere('user_id', $user->id));
    }
}
