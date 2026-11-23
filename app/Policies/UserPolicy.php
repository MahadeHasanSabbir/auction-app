<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Accounts in the admin panel. Platform staff look after buyers; the system
 * owner also manages the staff. Nobody suspends themselves or the system
 * owner, and seller accounts are handled through their company.
 */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function view(User $user, User $model): bool
    {
        return $this->isStaff($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, User $model): bool
    {
        return false;
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function suspend(User $user, User $model): bool
    {
        return $model->is_active && $this->oversees($user, $model);
    }

    public function reactivate(User $user, User $model): bool
    {
        return ! $model->is_active && $this->oversees($user, $model);
    }

    /** Wipe a buyer's personal details on their request (once). */
    public function anonymise(User $user, User $model): bool
    {
        return $model->role === UserRole::Buyer && $model->anonymised_at === null && $this->oversees($user, $model);
    }

    /** Add staff accounts and give them a new temporary password. */
    public function manageStaff(User $user): bool
    {
        return $user->is_active && $user->role === UserRole::SystemOwner;
    }

    private function oversees(User $user, User $model): bool
    {
        if (! $this->isStaff($user) || $user->is($model)) {
            return false;
        }

        return match ($model->role) {
            UserRole::Buyer => true,
            UserRole::PlatformStaff => $this->manageStaff($user),
            default => false,
        };
    }

    private function isStaff(User $user): bool
    {
        return $user->is_active && $user->isPlatform();
    }
}
