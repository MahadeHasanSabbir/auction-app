<?php

namespace App\Policies;

use App\Enums\FraudFlagStatus;
use App\Models\FraudFlag;
use App\Models\User;

/** Platform staff look at flags and decide them. */
final class FraudFlagPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isPlatform();
    }

    public function view(User $user, FraudFlag $flag): bool
    {
        return $this->viewAny($user);
    }

    public function review(User $user, FraudFlag $flag): bool
    {
        return $this->viewAny($user) && $flag->status === FraudFlagStatus::Open;
    }
}
