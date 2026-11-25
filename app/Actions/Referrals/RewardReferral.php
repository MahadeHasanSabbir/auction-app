<?php

namespace App\Actions\Referrals;

use App\Enums\LedgerTransactionType;
use App\Enums\OrderStatus;
use App\Enums\SystemAccount;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ledger\Ledger;
use App\Services\Notifications\AccountAlerts;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Pays the referral bonus, once, when a referred buyer has a completed
 * order: bonus credit from the Promotions account to the new buyer and to
 * the buyer who referred them (amounts in auction.engagement). A referrer
 * who is no longer an active buyer gets nothing; the new buyer still does.
 *
 * Lock order: the referred buyer's row, then the wallets.
 */
final class RewardReferral
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly AccountAlerts $alerts,
    ) {}

    /** True when a bonus was paid now. */
    public function handle(int $buyerId): bool
    {
        $forReferred = Money::minor(max(0, (int) config('auction.engagement.referral_bonus_referred')));
        $forReferrer = Money::minor(max(0, (int) config('auction.engagement.referral_bonus_referrer')));

        if ($forReferred->isZero() && $forReferrer->isZero()) {
            return false;
        }

        $paid = DB::transaction(function () use ($buyerId, $forReferred, $forReferrer): ?array {
            $buyer = User::query()->lockForUpdate()->find($buyerId);

            if ($buyer === null || $buyer->referred_by_id === null || $buyer->referral_rewarded_at !== null || ! $buyer->is_active) {
                return null;
            }

            if (! Order::query()->where('buyer_id', $buyer->id)->where('status', OrderStatus::Completed)->exists()) {
                return null;
            }

            $referrer = User::query()->whereKey($buyer->referred_by_id)->where('role', UserRole::Buyer)->where('is_active', true)->first();
            $forReferrer = $referrer === null ? Money::zero() : $forReferrer;
            $total = $forReferred->plus($forReferrer);

            $buyer->forceFill(['referral_rewarded_at' => now()])->save();

            if ($total->isZero()) {
                return null;
            }

            $this->ledger->transaction(LedgerTransactionType::Promotion)
                ->reference($buyer)
                ->idempotent("referral:{$buyer->id}")
                ->describe("Referral bonus for buyer {$buyer->id}")
                ->debit($this->ledger->system(SystemAccount::Promotions), $total)
                ->creditBonus(Wallet::query()->where('user_id', $buyer->id)->firstOrFail(), $forReferred)
                ->creditBonus($referrer === null ? Wallet::query()->where('user_id', $buyer->id)->firstOrFail() : Wallet::query()->where('user_id', $referrer->id)->firstOrFail(), $forReferrer)
                ->meta(['referrer_id' => $referrer?->id])
                ->post();

            return ['buyer' => $buyer, 'referrer' => $referrer];
        });

        if ($paid === null) {
            return false;
        }

        if ($forReferred->isPositive()) {
            $this->alerts->bonus($paid['buyer']->id, $forReferred, __('Thanks for joining through a friend’s invitation. Bonus credit pays for bids and purchases, and cannot be withdrawn.'));
        }

        if ($paid['referrer'] !== null && $forReferrer->isPositive()) {
            $this->alerts->bonus($paid['referrer']->id, $forReferrer, __(':name, whom you invited, completed their first order. Bonus credit pays for bids and purchases, and cannot be withdrawn.', ['name' => $paid['buyer']->maskedName()]));
        }

        return true;
    }
}
