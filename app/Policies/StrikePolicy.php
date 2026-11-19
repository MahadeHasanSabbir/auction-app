<?php

namespace App\Policies;

use App\Models\Strike;
use App\Models\User;

/** Platform staff give and revoke strikes against buyers. */
final class StrikePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isPlatform();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /** Give a strike to this account. */
    public function issue(User $user, User $account): bool
    {
        return $this->viewAny($user) && $account->isBuyer();
    }

    public function revoke(User $user, Strike $strike): bool
    {
        return $this->viewAny($user) && $strike->revoked_at === null;
    }
}
