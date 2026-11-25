<?php

namespace App\Actions\Billing;

use App\Enums\CouponTarget;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Coupons\Coupons;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The company owner takes a platform coupon off an open plan invoice. The
 * invoice amount becomes what is still due (the platform simply charges
 * less); one coupon per invoice. A gateway payment already started for the
 * old amount is forgotten, so it cannot settle the invoice.
 */
final class ApplyInvoiceCoupon
{
    public function __construct(private readonly Coupons $coupons) {}

    public function handle(User $user, SubscriptionInvoice $invoice, string $code): SubscriptionInvoice
    {
        $company = Company::query()->findOrFail($invoice->company_id);
        Gate::forUser($user)->authorize('manageBilling', $company);

        return DB::transaction(function () use ($user, $invoice, $company, $code): SubscriptionInvoice {
            $locked = SubscriptionInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvoiceStatus::Open || ! $locked->amount->isPositive()) {
                throw ValidationException::withMessages(['code' => __('This invoice can no longer be paid.')]);
            }

            if ($locked->coupon_id !== null) {
                throw ValidationException::withMessages(['code' => __('This invoice already has a coupon.')]);
            }

            $coupon = $this->coupons->claim($code, CouponTarget::PlanInvoice, $locked->amount, $user, $company);
            $discount = $coupon->discountOn($locked->amount);

            $locked->forceFill([
                'amount' => $locked->amount->minus($discount),
                'discount' => $discount,
                'coupon_id' => $coupon->id,
                'gateway' => null,
                'tran_id' => null,
                'gateway_reference' => null,
            ])->save();

            $this->coupons->redeem($coupon, $discount, $locked, $user, $company);

            return $invoice->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
