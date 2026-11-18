<?php

namespace App\Policies;

use App\Models\Dispute;
use App\Models\User;

/** Disputes are listed for platform staff; they are decided on the order (OrderPolicy::resolve). */
final class DisputePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isPlatform();
    }

    public function view(User $user, Dispute $dispute): bool
    {
        return $this->viewAny($user);
    }
}
