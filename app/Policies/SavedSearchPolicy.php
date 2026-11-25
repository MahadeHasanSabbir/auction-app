<?php

namespace App\Policies;

use App\Models\SavedSearch;
use App\Models\User;

/** Saved searches are a buyer's own. */
final class SavedSearchPolicy
{
    public function create(User $user): bool
    {
        return $user->is_active && $user->isBuyer();
    }

    public function update(User $user, SavedSearch $search): bool
    {
        return $user->is_active && $search->user_id === $user->id;
    }

    public function delete(User $user, SavedSearch $search): bool
    {
        return $this->update($user, $search);
    }
}
