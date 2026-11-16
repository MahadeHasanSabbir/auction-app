<?php

namespace App\Enums;

enum WalletType: string
{
    case Buyer = 'buyer';
    case Company = 'company';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Buyer => __('Buyer wallet'),
            self::Company => __('Company wallet'),
            self::System => __('Platform account'),
        };
    }
}
