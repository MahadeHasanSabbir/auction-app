<?php

namespace App\Policies;

use App\Models\ApiRequest;
use App\Models\User;

/** The API request log is read by platform accounts and never changed. */
final class ApiRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isPlatform();
    }

    public function view(User $user, ApiRequest $request): bool
    {
        return $this->viewAny($user);
    }
}
