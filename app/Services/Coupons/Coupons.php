<?php

namespace App\Services\Coupons;

use App\Enums\CouponTarget;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Checks and redeems coupon codes. claim() locks the coupon row, so two
 * uses of the last redemption cannot both pass; callers lock their own
 * domain row (invoice, auction, order) first and wallets after. Every
 * refusal is a ValidationException on "code".
 */
final class Coupons
{
    /**
     * The coupon behind $code, locked and checked for this use. $seller is
     * the company whose order it is (seller coupons only); $user and
     * $company are who uses it, for the per-user limit.
     */
    public function claim(string $code, CouponTarget $target, Money $amount, ?User $user, ?Company $company = null, ?Company $seller = null): Coupon
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Coupons are claimed inside a database transaction.');
        }

        $code = Coupon::normalise($code);

        $coupon = $code === '' ? null : Coupon::query()->where('code', $code)->lockForUpdate()->first();

        if ($coupon === null || $coupon->target !== $target || $coupon->company_id !== $seller?->id) {
            throw $this->refuse(__('This code is not valid here.'));
        }

        if (! $coupon->is_active || ($coupon->starts_at !== null && now()->lessThan($coupon->starts_at))) {
            throw $this->refuse(__('This code is not active.'));
        }

        if ($coupon->ends_at !== null && now()->greaterThanOrEqualTo($coupon->ends_at)) {
            throw $this->refuse(__('This code has expired.'));
        }

        if ($coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses) {
            throw $this->refuse(__('This code has been used up.'));
        }

        if ($coupon->min_amount !== null && $amount->lessThan($coupon->min_amount)) {
            throw $this->refuse(__('This code needs an amount of at least :amount.', ['amount' => $coupon->min_amount->format()]));
        }

        $uses = CouponRedemption::query()
            ->where('coupon_id', $coupon->id)
            ->when($target->usedByCompany(),
                fn ($query) => $query->where('company_id', ($company ?? throw new LogicException('A company coupon needs the company using it.'))->id),
                fn ($query) => $query->where('user_id', ($user ?? throw new LogicException('A buyer coupon needs the buyer using it.'))->id))
            ->count();

        if ($uses >= $coupon->per_user_limit) {
            throw $this->refuse(__('You have already used this code.'));
        }

        return $coupon;
    }

    /** Records a use of a claimed coupon on $subject. */
    public function redeem(Coupon $coupon, Money $discount, Model $subject, ?User $user, ?Company $company = null): CouponRedemption
    {
        $redemption = new CouponRedemption;
        $redemption->forceFill([
            'coupon_id' => $coupon->id,
            'user_id' => $user?->id,
            'company_id' => $company?->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'amount' => $discount,
            'created_at' => now(),
        ])->save();

        $coupon->forceFill(['used_count' => $coupon->used_count + 1])->save();

        return $redemption;
    }

    private function refuse(string $message): ValidationException
    {
        return ValidationException::withMessages(['code' => $message]);
    }
}
