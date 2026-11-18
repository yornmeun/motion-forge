<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role_id', 'monthly_budget_usd', 'budget_warning_pct'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'monthly_budget_usd' => 'decimal:4'];
    }

    public function role(): BelongsTo { return $this->belongsTo(Role::class); }
    public function projects(): HasMany { return $this->hasMany(Project::class); }
    public function sharedProjects(): BelongsToMany { return $this->belongsToMany(Project::class, 'project_members')->withPivot('role'); }
    public function credentials(): HasMany { return $this->hasMany(AiCredential::class); }

    public function hasPermission(string $slug): bool
    {
        if (! $this->role) {
            return false;
        }

        return $this->role->slug === 'admin' || $this->role->permissions->contains('slug', $slug);
    }

    public function isAdmin(): bool
    {
        return $this->role?->slug === 'admin';
    }

    public function monthSpendUsd(): float
    {
        return (float) AiRequest::where('user_id', $this->id)->where('created_at', '>=', now()->startOfMonth())->sum('estimated_cost_usd');
    }
}
