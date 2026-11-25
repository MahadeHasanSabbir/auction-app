<?php

namespace App\Policies;

use App\Models\Notice;
use App\Models\User;

/** Platform staff write the notices; everyone reads the published ones on the site. */
final class NoticePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function view(User $user, Notice $notice): bool
    {
        return $this->isStaff($user);
    }

    public function create(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function update(User $user, Notice $notice): bool
    {
        return $this->isStaff($user);
    }

    public function delete(User $user, Notice $notice): bool
    {
        return $this->isStaff($user);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function isStaff(User $user): bool
    {
        return $user->is_active && $user->isPlatform();
    }
}
