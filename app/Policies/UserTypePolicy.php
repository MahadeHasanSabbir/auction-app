<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserType;

/** User types are set by the system owner. One in use is switched off, not deleted. */
final class UserTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function view(User $user, UserType $type): bool
    {
        return $this->isOwner($user);
    }

    public function create(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function update(User $user, UserType $type): bool
    {
        return $this->isOwner($user);
    }

    public function delete(User $user, UserType $type): bool
    {
        return $this->isOwner($user)
            && ! $type->users()->withTrashed()->exists()
            && ! $type->companies()->withTrashed()->exists()
            && ! $type->feeRules()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function isOwner(User $user): bool
    {
        return $user->is_active && $user->role === UserRole::SystemOwner;
    }
}
