<?php

namespace App\Policies;

use App\Models\Deposit;
use App\Models\User;

/** Platform staff can look at every deposit; nobody edits one by hand. */
final class DepositPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isPlatform();
    }

    public function view(User $user, Deposit $deposit): bool
    {
        return $user->is_active && ($user->isPlatform() || $deposit->user_id === $user->id);
    }
}
