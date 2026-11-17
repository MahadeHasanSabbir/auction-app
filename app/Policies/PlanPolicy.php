<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\User;

/** Plans and company agreements decide what the platform earns: the system owner alone manages them. */
class PlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->owner($user);
    }

    public function view(User $user, Plan $model): bool
    {
        return $this->owner($user);
    }

    public function create(User $user): bool
    {
        return $this->owner($user);
    }

    public function update(User $user, Plan $model): bool
    {
        return $this->owner($user);
    }

    public function delete(User $user, Plan $model): bool
    {
        return $this->owner($user);
    }

    private function owner(User $user): bool
    {
        return $user->is_active && $user->role === UserRole::SystemOwner;
    }
}
