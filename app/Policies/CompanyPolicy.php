<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;

/**
 * Companies are administered by the system owner only. A company's own
 * owner runs its team and billing; members can look.
 */
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->administers($user);
    }

    public function view(User $user, Company $company): bool
    {
        return $this->administers($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Company $company): bool
    {
        return $this->administers($user);
    }

    public function delete(User $user, Company $company): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /** Invite or remove members. A suspended company cannot grow. */
    public function manageTeam(User $user, Company $company): bool
    {
        return $this->ownsCompany($user, $company) && ! $company->isSuspended();
    }

    /** Choose or change the plan, pay invoices, request payouts. */
    public function manageBilling(User $user, Company $company): bool
    {
        return $this->ownsCompany($user, $company);
    }

    /** Edit the public profile. */
    public function manageProfile(User $user, Company $company): bool
    {
        return $this->ownsCompany($user, $company);
    }

    private function administers(User $user): bool
    {
        return $user->is_active && $user->role === UserRole::SystemOwner;
    }

    private function ownsCompany(User $user, Company $company): bool
    {
        return $user->is_active
            && $user->role === UserRole::CompanyOwner
            && $user->company_id === $company->id;
    }
}
