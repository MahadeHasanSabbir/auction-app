<?php

namespace App\Policies;

use App\Models\Page;
use App\Models\User;

/**
 * Platform staff edit the site's text pages; everyone reads them on the
 * site. The set of pages is fixed (SitePage), so nobody creates or deletes one.
 */
final class PagePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function view(User $user, Page $page): bool
    {
        return $this->isStaff($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Page $page): bool
    {
        return $this->isStaff($user);
    }

    public function delete(User $user, Page $page): bool
    {
        return false;
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
