<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * Products belong to a seller company; anyone on its team manages them.
 * Platform staff can look at every product (they review the auctions).
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->isSeller() || $user->isPlatform());
    }

    public function view(User $user, Product $product): bool
    {
        return $user->is_active && ($user->isPlatform() || $this->onTeam($user, $product));
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->isSeller();
    }

    /** Not while one of its auctions is under review or approved: the platform approved what it saw. */
    public function update(User $user, Product $product): bool
    {
        return $this->onTeam($user, $product) && ! $product->isLocked();
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->onTeam($user, $product);
    }

    private function onTeam(User $user, Product $product): bool
    {
        return $user->is_active && $user->isSeller() && $user->company_id === $product->company_id;
    }
}
