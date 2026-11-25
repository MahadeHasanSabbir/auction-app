<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/** Marketplace settings belong to the system owner. */
final class SettingPolicy
{
    public function manage(User $user): bool
    {
        return $user->is_active && $user->role === UserRole::SystemOwner;
    }
}
