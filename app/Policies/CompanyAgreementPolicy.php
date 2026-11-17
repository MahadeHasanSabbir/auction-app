<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\CompanyAgreement;
use App\Models\User;

/** Plans and company agreements decide what the platform earns: the system owner alone manages them. */
class CompanyAgreementPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->owner($user);
    }

    public function view(User $user, CompanyAgreement $model): bool
    {
        return $this->owner($user);
    }

    public function create(User $user): bool
    {
        return $this->owner($user);
    }

    public function update(User $user, CompanyAgreement $model): bool
    {
        return $this->owner($user);
    }

    public function delete(User $user, CompanyAgreement $model): bool
    {
        return $this->owner($user);
    }

    private function owner(User $user): bool
    {
        return $user->is_active && $user->role === UserRole::SystemOwner;
    }
}
