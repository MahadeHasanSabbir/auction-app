<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/** The activity log is read by the system owner and never changed. */
final class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->role === UserRole::SystemOwner;
    }

    public function view(User $user, Activity $activity): bool
    {
        return $this->viewAny($user);
    }
}
