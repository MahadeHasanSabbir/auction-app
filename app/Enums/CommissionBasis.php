<?php

namespace App\Enums;

/** What a company agreement rate is applied to. */
enum CommissionBasis: string
{
    case FinalPrice = 'final_price';
    case AboveStartingPrice = 'above_starting_price';

    public function label(): string
    {
        return match ($this) {
            self::FinalPrice => __('Final price'),
            self::AboveStartingPrice => __('Amount above the starting price'),
        };
    }
}
