<?php

namespace App\Models;

use App\Enums\ProviderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An API credential. `secret` is encrypted at rest (Laravel encrypted cast) and hidden from
 * serialization. Server-managed credentials have user_id = null and read the key from env_key.
 */
class AiCredential extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'ai_provider_id', 'name', 'secret', 'env_key', 'key_hint', 'default_model', 'is_enabled', 'status', 'status_message', 'last_tested_at', 'rotated_at', 'monthly_limit_usd'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted', 'is_enabled' => 'boolean', 'status' => ProviderStatus::class,
            'last_tested_at' => 'datetime', 'rotated_at' => 'datetime', 'monthly_limit_usd' => 'decimal:4',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isServerManaged(): bool
    {
        return $this->user_id === null;
    }

    /** The plaintext key, for backend use only. Never pass this to a view or a log. */
    public function apiKey(): ?string
    {
        if ($this->isServerManaged()) {
            $keys = config('motionforge.server_keys', []);

            return array_key_exists((string) $this->env_key, $keys) ? ($keys[$this->env_key] ?: null) : null;
        }

        return $this->secret;
    }

    public function maskedKey(): string
    {
        return $this->isServerManaged() ? 'server: '.$this->env_key : '••••••••••••'.($this->key_hint ?? '');
    }

    public function isUsable(): bool
    {
        return $this->is_enabled && $this->status === ProviderStatus::Connected;
    }

    public static function hintFor(string $key): string
    {
        return substr($key, -4);
    }

    public function scopeVisibleTo($query, User $user)
    {
        return $query->where(fn ($q) => $q->where('user_id', $user->id)->orWhereNull('user_id'));
    }
}
