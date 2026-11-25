<?php

namespace App\Enums;

enum CouponDiscountType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Percent => __('Percentage'),
            self::Fixed => __('Fixed amount'),
        };
    }
}
