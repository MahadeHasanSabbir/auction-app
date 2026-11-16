<?php

namespace App\Enums;

/** How a row in the bids table came about. */
enum BidType: string
{
    case Manual = 'manual';
    case Proxy = 'proxy';
    case BuyNow = 'buy_now';

    public function label(): string
    {
        return match ($this) {
            self::Manual => __('Manual bid'),
            self::Proxy => __('Automatic bid'),
            self::BuyNow => __('Buy now'),
        };
    }
}
