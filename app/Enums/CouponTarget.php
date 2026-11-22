<?php

namespace App\Enums;

/** What a coupon takes money off, or gives. */
enum CouponTarget: string
{
    /** A seller's plan invoice (platform coupon). */
    case PlanInvoice = 'plan_invoice';
    /** The featured-listing fee (platform coupon). */
    case FeaturedFee = 'featured_fee';
    /** Bonus credit in a buyer's wallet (platform coupon). */
    case WalletBonus = 'wallet_bonus';
    /** A buyer's order with the company that made the coupon (seller coupon). */
    case Order = 'order';

    public function label(): string
    {
        return match ($this) {
            self::PlanInvoice => __('Plan invoice'),
            self::FeaturedFee => __('Featured listing fee'),
            self::WalletBonus => __('Wallet bonus credit'),
            self::Order => __('Order discount'),
        };
    }

    /**
     * Targets the platform's own coupons may have.
     *
     * @return list<self>
     */
    public static function platform(): array
    {
        return [self::PlanInvoice, self::FeaturedFee, self::WalletBonus];
    }

    /** Whether a company (not a buyer) uses coupons of this target. */
    public function usedByCompany(): bool
    {
        return $this === self::PlanInvoice || $this === self::FeaturedFee;
    }
}
