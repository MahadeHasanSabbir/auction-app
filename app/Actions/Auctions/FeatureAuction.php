<?php

namespace App\Actions\Auctions;

use App\Actions\Auctions\Concerns\AuctionRules;
use App\Enums\AuctionStatus;
use App\Enums\CouponTarget;
use App\Enums\FeeChargeType;
use App\Enums\FeeUseCase;
use App\Enums\LedgerComponent;
use App\Enums\LedgerTransactionType;
use App\Enums\SystemAccount;
use App\Models\Auction;
use App\Models\Company;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Coupons\Coupons;
use App\Services\Fees\Fee;
use App\Services\Fees\FeeResolver;
use App\Services\Invoices\Invoices;
use App\Services\Ledger\InsufficientFundsException;
use App\Services\Ledger\Ledger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The company owner pays to feature a scheduled or live auction for the
 * rest of its run: it leads the auction list and shows on the home page.
 * The price is the platform's featured-listing fee rule (on the starting
 * price), less an optional platform coupon, paid from the company wallet
 * into service revenue with VAT included. Nothing is refunded if the
 * auction is cancelled later.
 *
 * Lock order: the auction row, then the coupon, then the wallets.
 */
final class FeatureAuction
{
    use AuctionRules;

    public function __construct(
        private readonly FeeResolver $fees,
        private readonly Ledger $ledger,
        private readonly Coupons $coupons,
        private readonly Invoices $invoices,
    ) {}

    /** The fee for featuring $auction now, or null when featuring is not offered. */
    public function quote(Auction $auction): ?Fee
    {
        $company = Company::query()->findOrFail($auction->company_id);
        $fee = $this->fees->charge(FeeUseCase::FeaturedListing, FeeChargeType::Service, $auction->starting_price, $company->user_type_id);

        return $fee->rule === null ? null : $fee;
    }

    public function handle(User $user, Auction $auction, ?string $code = null): Auction
    {
        $company = Company::query()->findOrFail($auction->company_id);
        Gate::forUser($user)->authorize('manageBilling', $company);

        return DB::transaction(function () use ($user, $auction, $company, $code): Auction {
            $locked = Auction::query()->lockForUpdate()->findOrFail($auction->id);

            if (! in_array($locked->status, [AuctionStatus::Scheduled, AuctionStatus::Live], true)) {
                throw ValidationException::withMessages(['auction' => __('Only a scheduled or live auction can be featured.')]);
            }

            if ($locked->featured_at !== null) {
                throw ValidationException::withMessages(['auction' => __('This auction is already featured.')]);
            }

            $fee = $this->quote($locked)
                ?? throw ValidationException::withMessages(['auction' => __('Featured listings are not offered right now.')]);

            $discount = Money::zero($fee->amount->currency);
            $coupon = null;

            if ($code !== null && trim($code) !== '') {
                $coupon = $this->coupons->claim($code, CouponTarget::FeaturedFee, $fee->amount, $user, $company);
                $discount = $coupon->discountOn($fee->amount);
            }

            $charge = $fee->amount->minus($discount);
            $transaction = null;

            if ($charge->isPositive()) {
                try {
                    $transaction = $this->ledger->transaction(LedgerTransactionType::FeaturedListing)
                        ->reference($locked)
                        ->idempotent("featured:{$locked->id}")
                        ->describe("Featured listing for auction {$locked->id}")
                        ->by($user)
                        ->debit(Wallet::query()->where('company_id', $company->id)->firstOrFail(), $charge)
                        ->creditWithVat($this->ledger->system(SystemAccount::ServiceRevenue), $charge, LedgerComponent::ServiceCharge, $fee)
                        ->meta(['fee' => $fee->amount->amount, 'discount' => $discount->amount])
                        ->post();
                } catch (InsufficientFundsException $e) {
                    throw ValidationException::withMessages(['auction' => __('The company wallet has :available available; featuring costs :amount.', [
                        'available' => $e->available->format(),
                        'amount' => $charge->format(),
                    ])]);
                }
            }

            if ($coupon !== null) {
                $this->coupons->redeem($coupon, $discount, $locked, $user, $company);
            }

            $locked->forceFill(['featured_at' => now()])->save();

            if ($transaction !== null) {
                $this->invoices->featured($locked, $fee->amount, $discount, $transaction);
            }

            $this->record($user, $locked, 'featured', [
                'fee' => $fee->amount->amount,
                'discount' => $discount->amount,
                'coupon' => $coupon?->code,
            ]);

            return $auction->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
