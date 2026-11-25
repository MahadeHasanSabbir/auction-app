<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\ChangesOrders;
use App\Enums\CouponTarget;
use App\Enums\LedgerTransactionType;
use App\Enums\OrderStatus;
use App\Enums\SystemAccount;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Coupons\Coupons;
use App\Services\Ledger\Ledger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The buyer uses a seller's coupon on an order before it ships. The
 * discount goes back from escrow to the buyer's wallet at once (as bonus
 * credit for the part they paid with bonus credit), and the company is
 * paid that much less. The platform's commission stays on the full price,
 * so the discount can never be more than the price less the commission.
 *
 * Lock order: the order row, then the coupon, then the wallets.
 */
final class ApplyOrderCoupon
{
    use ChangesOrders;

    public function __construct(
        private readonly Coupons $coupons,
        private readonly Ledger $ledger,
    ) {}

    public function handle(User $buyer, Order $order, string $code): Order
    {
        Gate::forUser($buyer)->authorize('applyCoupon', $order);

        return DB::transaction(function () use ($buyer, $order, $code): Order {
            $locked = $this->lockOrder($order, OrderStatus::InEscrow);

            if ($locked->coupon_id !== null) {
                throw ValidationException::withMessages(['code' => __('This order already has a coupon.')]);
            }

            $seller = Company::query()->findOrFail($locked->company_id);
            $coupon = $this->coupons->claim($code, CouponTarget::Order, $locked->amount, $buyer, seller: $seller);

            $room = $locked->amount->minus($locked->commission);
            $discount = $coupon->discountOn($locked->amount);
            $discount = $discount->greaterThan($room) ? $room : $discount;

            if (! $discount->isPositive()) {
                throw ValidationException::withMessages(['code' => __('This code takes nothing off this order.')]);
            }

            $bonus = Money::minor(min($locked->bonus_amount->amount, $discount->amount), $discount->currency);

            $this->ledger->transaction(LedgerTransactionType::CouponDiscount)
                ->reference($locked)
                ->idempotent("order-coupon:{$locked->id}")
                ->describe("Seller coupon {$coupon->code} on order {$locked->number}")
                ->by($buyer)
                ->debit($this->ledger->system(SystemAccount::Escrow), $discount)
                ->creditBonus(Wallet::query()->where('user_id', $locked->buyer_id)->firstOrFail(), $discount, $bonus)
                ->meta(['coupon_id' => $coupon->id])
                ->post();

            $locked->forceFill([
                'discount' => $discount,
                'coupon_id' => $coupon->id,
                'bonus_amount' => $locked->bonus_amount->minus($bonus),
                'seller_payout' => $locked->amount->minus($discount)->minus($locked->commission),
            ])->save();

            $this->coupons->redeem($coupon, $discount, $locked, $buyer);

            $this->record($locked, 'discounted', __('The buyer used coupon :code: :amount off, returned to their wallet.', [
                'code' => $coupon->code,
                'amount' => $discount->format(),
            ]), $buyer, meta: ['coupon' => $coupon->code, 'discount' => $discount->amount]);

            return $this->syncFrom($order, $locked);
        });
    }
}
