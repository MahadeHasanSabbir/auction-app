<?php

namespace App\Enums;

enum HoldType: string
{
    case Bid = 'bid';
    case Withdrawal = 'withdrawal';

    public function label(): string
    {
        return match ($this) {
            self::Bid => __('Bid'),
            self::Withdrawal => __('Withdrawal'),
        };
    }
}
