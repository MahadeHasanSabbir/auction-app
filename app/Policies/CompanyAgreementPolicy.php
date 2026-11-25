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

    /**
     * Only before it takes effect: an agreement in force may already be in an
     * approved auction's fee snapshot and in ledger rows, which point at it.
     * After that it is ended and replaced, like a fee rule.
     */
    public function update(User $user, CompanyAgreement $model): bool
    {
        return $this->owner($user) && $model->effective_from->isFuture();
    }

    public function delete(User $user, CompanyAgreement $model): bool
    {
        return $this->update($user, $model);
    }

    /** Stop an agreement from now on; auctions approved under it keep it. */
    public function end(User $user, CompanyAgreement $model): bool
    {
        return $this->owner($user) && ! $model->effective_from->isFuture()
            && ($model->effective_until === null || $model->effective_until->isFuture());
    }

    private function owner(User $user): bool
    {
        return $user->is_active && $user->role === UserRole::SystemOwner;
    }
}
