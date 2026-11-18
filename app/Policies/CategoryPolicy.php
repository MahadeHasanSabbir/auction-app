<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

/** Platform staff keep the category tree. A category in use is switched off, not deleted. */
final class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function view(User $user, Category $category): bool
    {
        return $this->isStaff($user);
    }

    public function create(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function update(User $user, Category $category): bool
    {
        return $this->isStaff($user);
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->isStaff($user)
            && ! $category->children()->exists()
            && ! $category->products()->withTrashed()->exists();
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
