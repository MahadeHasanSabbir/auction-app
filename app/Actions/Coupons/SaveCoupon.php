<?php

namespace App\Actions\Coupons;

use App\Enums\CouponDiscountType;
use App\Enums\CouponTarget;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or changes a coupon: platform staff for the platform's own
 * coupons ($company null), the company owner for the company's order
 * coupons. Once a coupon has been used, its code and what it is for stay
 * as they are, so redemptions keep their meaning.
 *
 * $data: code, target, discount_type, value (basis points or minor units),
 * max_discount, min_amount (Money|null), max_uses, per_user_limit,
 * starts_at, ends_at, is_active, description.
 */
final class SaveCoupon
{
    /** @param  array<string, mixed>  $data */
    public function handle(User $by, array $data, ?Company $company = null, ?Coupon $coupon = null): Coupon
    {
        if ($coupon === null) {
            Gate::forUser($by)->authorize('create', [Coupon::class, $company]);
        } else {
            Gate::forUser($by)->authorize('update', $coupon);
            $company = $coupon->company_id === null ? null : Company::query()->findOrFail($coupon->company_id);
        }

        $data['code'] = Coupon::normalise((string) ($data['code'] ?? ''));
        $data['target'] = $company !== null ? CouponTarget::Order->value : ($data['target'] instanceof CouponTarget ? $data['target']->value : $data['target'] ?? null);
        $data['discount_type'] = $data['discount_type'] instanceof CouponDiscountType ? $data['discount_type']->value : $data['discount_type'] ?? null;

        $targets = $company !== null ? [CouponTarget::Order] : CouponTarget::platform();

        // Bonus credit is money the platform gives away: the system owner's call.
        if (! $by->isSystemOwner()) {
            $targets = array_values(array_filter($targets, fn (CouponTarget $target) => $target !== CouponTarget::WalletBonus));

            if ($coupon?->target === CouponTarget::WalletBonus) {
                throw new AuthorizationException(__('Only the system owner manages wallet bonus coupons.'));
            }
        }

        $valid = Validator::make($data, [
            'code' => ['required', 'string', 'min:3', 'max:32', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('coupons', 'code')->ignore($coupon?->id)],
            'target' => ['required', Rule::in(array_map(fn (CouponTarget $target) => $target->value, $targets))],
            'discount_type' => ['required', Rule::enum(CouponDiscountType::class)],
            'value' => ['required', 'integer', 'min:1'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['required', 'integer', 'min:1', 'max:1000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['boolean'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'code.regex' => __('Use capital letters, digits, dashes and underscores only.'),
        ])->validate();

        $type = CouponDiscountType::from($valid['discount_type']);

        if ($type === CouponDiscountType::Percent && $valid['value'] > 10_000) {
            throw ValidationException::withMessages(['value' => __('A percentage is at most 100.')]);
        }

        if ($valid['target'] === CouponTarget::WalletBonus->value && $type !== CouponDiscountType::Fixed) {
            throw ValidationException::withMessages(['discount_type' => __('Bonus credit is a fixed amount.')]);
        }

        foreach (['max_discount', 'min_amount'] as $key) {
            if (isset($data[$key]) && ! $data[$key] instanceof Money) {
                throw ValidationException::withMessages([$key => __('Enter an amount.')]);
            }
        }

        if ($coupon !== null && $coupon->used_count > 0 && ($coupon->code !== $valid['code'] || $coupon->target->value !== $valid['target'])) {
            throw ValidationException::withMessages(['code' => __('This coupon has been used: its code and purpose cannot change.')]);
        }

        return DB::transaction(function () use ($by, $company, $coupon, $valid, $type, $data): Coupon {
            $saving = $coupon ?? new Coupon;
            $saving->fill([
                'code' => $valid['code'],
                'discount_type' => $type,
                'value' => $valid['value'],
                'max_discount' => $type === CouponDiscountType::Percent ? ($data['max_discount'] ?? null) : null,
                'min_amount' => $data['min_amount'] ?? null,
                'max_uses' => $valid['max_uses'] ?? null,
                'per_user_limit' => $valid['per_user_limit'],
                'starts_at' => $valid['starts_at'] ?? null,
                'ends_at' => $valid['ends_at'] ?? null,
                'is_active' => (bool) ($valid['is_active'] ?? true),
                'description' => $valid['description'] ?? null,
            ]);
            $saving->target = CouponTarget::from($valid['target']);

            if ($coupon === null) {
                $saving->company_id = $company?->id;
                $saving->created_by = $by->id;
            }

            $saving->save();

            activity($company === null ? 'admin' : 'company')
                ->causedBy($by)
                ->performedOn($saving)
                ->event($coupon === null ? 'created' : 'updated')
                ->withProperties(['code' => $saving->code, 'target' => $saving->target->value, 'value' => $saving->value, 'type' => $type->value])
                ->log(($coupon === null ? 'created' : 'updated')." coupon {$saving->code}");

            return $saving;
        });
    }
}
