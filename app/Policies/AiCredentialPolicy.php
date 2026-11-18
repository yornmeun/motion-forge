<?php

namespace App\Policies;

use App\Models\AiCredential;
use App\Models\User;

/** Users manage only their own credentials; server-managed ones are admin-only. */
class AiCredentialPolicy
{
    public function use(User $user, AiCredential $c): bool
    {
        return $c->user_id === $user->id || ($c->user_id === null && $c->is_enabled);
    }

    public function manage(User $user, AiCredential $c): bool
    {
        return $c->user_id === null ? $user->isAdmin() : ($c->user_id === $user->id && $user->hasPermission('credentials.manage'));
    }
}
