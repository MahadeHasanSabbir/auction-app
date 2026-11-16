<?php

namespace App\Enums;

/** Why an auction reached a final status. */
enum AuctionCloseReason: string
{
    case Sold = 'sold';
    case BuyNow = 'buy_now';
    case ReserveNotMet = 'reserve_not_met';
    case NoBids = 'no_bids';
    case CancelledBySeller = 'cancelled_by_seller';
    case CancelledByPlatform = 'cancelled_by_platform';

    public function label(): string
    {
        return match ($this) {
            self::Sold => __('Sold to the highest bidder'),
            self::BuyNow => __('Bought at the buy-now price'),
            self::ReserveNotMet => __('Reserve price not met'),
            self::NoBids => __('No bids'),
            self::CancelledBySeller => __('Cancelled by the seller'),
            self::CancelledByPlatform => __('Cancelled by the platform'),
        };
    }
}
