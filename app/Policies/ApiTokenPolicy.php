<?php

namespace App\Policies;

use App\Models\ApiToken;
use App\Models\User;

/** Platform accounts see who uses the API and can cut a token off; nobody edits one. */
final class ApiTokenPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isPlatform();
    }

    public function view(User $user, ApiToken $token): bool
    {
        return $this->viewAny($user);
    }

    public function revoke(User $user, ApiToken $token): bool
    {
        return $this->viewAny($user);
    }
}
