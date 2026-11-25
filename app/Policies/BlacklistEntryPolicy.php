<?php

namespace App\Policies;

use App\Models\BlacklistEntry;
use App\Models\User;

/** Platform staff keep the blacklist. */
final class BlacklistEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isPlatform();
    }

    public function view(User $user, BlacklistEntry $entry): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, BlacklistEntry $entry): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, BlacklistEntry $entry): bool
    {
        return $this->viewAny($user);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
