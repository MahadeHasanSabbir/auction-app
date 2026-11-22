<?php

namespace App\Actions\Wallet;

use App\Enums\CouponDiscountType;
use App\Enums\CouponTarget;
use App\Enums\LedgerTransactionType;
use App\Enums\SystemAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Coupons\Coupons;
use App\Services\Ledger\Ledger;
use App\Services\Notifications\AccountAlerts;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A buyer redeems a platform wallet-bonus coupon: its fixed amount is
 * posted from the Promotions account into their wallet as bonus credit,
 * which buys at auction but is never withdrawn.
 */
final class RedeemBonusCoupon
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Coupons $coupons,
        private readonly AccountAlerts $alerts,
    ) {}

    public function handle(User $buyer, string $code): Money
    {
        if (! $buyer->is_active || ! $buyer->isBuyer()) {
            throw ValidationException::withMessages(['code' => __('Only buyers can redeem bonus codes.')]);
        }

        $wallet = Wallet::query()->where('user_id', $buyer->id)->firstOrFail();

        $amount = DB::transaction(function () use ($buyer, $wallet, $code): Money {
            $coupon = $this->coupons->claim($code, CouponTarget::WalletBonus, Money::zero(), $buyer);

            if ($coupon->discount_type !== CouponDiscountType::Fixed || $coupon->value <= 0) {
                throw ValidationException::withMessages(['code' => __('This code is not valid here.')]);
            }

            $amount = Money::minor($coupon->value);
            $redemption = $this->coupons->redeem($coupon, $amount, $buyer, $buyer);

            $this->ledger->transaction(LedgerTransactionType::Promotion)
                ->reference($buyer)
                ->idempotent("coupon:{$redemption->id}")
                ->describe("Bonus code {$coupon->code}")
                ->by($buyer)
                ->debit($this->ledger->system(SystemAccount::Promotions), $amount)
                ->creditBonus($wallet, $amount)
                ->meta(['coupon_id' => $coupon->id])
                ->post();

            return $amount;
        });

        $this->alerts->bonus($buyer->id, $amount, __('Bonus code redeemed. Bonus credit pays for bids and purchases, and cannot be withdrawn.'));

        return $amount;
    }
}
