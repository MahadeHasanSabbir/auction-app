<?php

namespace App\Enums;

/** What a fee rule or a company agreement rate applies to. */
enum FeeUseCase: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case SaleCommission = 'sale_commission';
    case BuyerPremium = 'buyer_premium';
    case BuyNow = 'buy_now';
    case Listing = 'listing';
    case FeaturedListing = 'featured_listing';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => __('Deposit'),
            self::Withdrawal => __('Withdrawal'),
            self::SaleCommission => __('Sale commission'),
            self::BuyerPremium => __('Buyer premium'),
            self::BuyNow => __('Buy now'),
            self::Listing => __('Listing fee'),
            self::FeaturedListing => __('Featured listing'),
        };
    }
}
