<?php

namespace App\Enums;

enum DisputeResolution: string
{
    case RefundFull = 'refund_full';
    case RefundPartial = 'refund_partial';
    case ReleaseToSeller = 'release_to_seller';

    public function label(): string
    {
        return match ($this) {
            self::RefundFull => __('Full refund to the buyer'),
            self::RefundPartial => __('Partial refund to the buyer'),
            self::ReleaseToSeller => __('Released to the seller'),
        };
    }
}
