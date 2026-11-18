<?php

namespace App\Policies;

use App\Enums\WithdrawalStatus;
use App\Models\User;
use App\Models\WithdrawalRequest;

final class WithdrawalRequestPolicy
{
    /** The admin panel's payout desk. */
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isPlatform();
    }

    public function view(User $user, WithdrawalRequest $withdrawal): bool
    {
        return $user->isPlatform() || $withdrawal->requested_by === $user->id;
    }

    /** The person who asked may take it back until staff have looked at it. */
    public function cancel(User $user, WithdrawalRequest $withdrawal): bool
    {
        return $withdrawal->requested_by === $user->id && $withdrawal->status === WithdrawalStatus::Pending;
    }

    /** Approving, paying out and rejecting are for platform staff. */
    public function review(User $user, WithdrawalRequest $withdrawal): bool
    {
        return $user->isPlatform() && $user->is_active && $withdrawal->requested_by !== $user->id;
    }
}
