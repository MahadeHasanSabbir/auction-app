<?php

namespace App\Policies;

use App\Enums\FeeUseCase;
use App\Enums\UserRole;
use App\Models\FeeRule;
use App\Models\User;

/**
 * Platform staff set the charges. A rule that has taken effect is never
 * edited, because deposits and withdrawals point at it: it is ended and a
 * new one added. A rule that has not started yet can still be changed.
 */
final class FeeRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function view(User $user, FeeRule $rule): bool
    {
        return $this->isStaff($user);
    }

    public function create(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function update(User $user, FeeRule $rule): bool
    {
        return $this->mayManage($user, $rule) && $rule->effective_from->isFuture();
    }

    /** Stop a rule from now on. */
    public function end(User $user, FeeRule $rule): bool
    {
        return $this->mayManage($user, $rule) && $rule->is_active
            && ($rule->effective_until === null || $rule->effective_until->isFuture());
    }

    public function delete(User $user, FeeRule $rule): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /** The default sale commission is the system owner's, like company agreements. */
    private function mayManage(User $user, FeeRule $rule): bool
    {
        return $rule->use_case === FeeUseCase::SaleCommission
            ? $user->is_active && $user->role === UserRole::SystemOwner
            : $this->isStaff($user);
    }

    private function isStaff(User $user): bool
    {
        return $user->is_active && $user->isPlatform();
    }
}
