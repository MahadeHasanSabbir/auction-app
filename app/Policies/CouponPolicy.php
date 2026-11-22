<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\User;

/**
 * Platform staff keep the platform's coupons; a company's team sees its
 * order coupons and the owner manages them. A coupon that has been used is
 * never deleted (switch it off instead).
 */
final class CouponPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->isPlatform() || $user->isSeller());
    }

    public function view(User $user, Coupon $coupon): bool
    {
        return $user->is_active && ($coupon->company_id === null
            ? $user->isPlatform()
            : $user->isSeller() && $user->company_id === $coupon->company_id);
    }

    /** $company: the company the coupon is for, or null for a platform coupon. */
    public function create(User $user, ?Company $company = null): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $company === null
            ? $user->isPlatform()
            : $user->role === UserRole::CompanyOwner && $user->company_id === $company->id;
    }

    public function update(User $user, Coupon $coupon): bool
    {
        return $user->is_active && ($coupon->company_id === null
            ? $user->isPlatform()
            : $user->role === UserRole::CompanyOwner && $user->company_id === $coupon->company_id);
    }

    public function delete(User $user, Coupon $coupon): bool
    {
        return $coupon->used_count === 0 && $this->update($user, $coupon);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
