<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Wallet;

final class WalletPolicy
{
    /** A buyer withdraws from their own wallet, a company owner from the company's. */
    public function withdraw(User $user, Wallet $wallet): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return match ($user->role) {
            UserRole::Buyer => $wallet->user_id === $user->id,
            UserRole::CompanyOwner => $wallet->company_id !== null && $wallet->company_id === $user->company_id,
            default => false,
        };
    }

    /** Platform staff fine a buyer's or a company's wallet. */
    public function fine(User $user, Wallet $wallet): bool
    {
        return $user->is_active && $user->isPlatform() && ($wallet->user_id !== null || $wallet->company_id !== null);
    }
}
