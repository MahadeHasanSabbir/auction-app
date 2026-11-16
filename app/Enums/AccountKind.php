<?php

namespace App\Enums;

/** The two kinds of account a user type can apply to. */
enum AccountKind: string
{
    case Buyer = 'buyer';
    case Seller = 'seller';

    public function label(): string
    {
        return match ($this) {
            self::Buyer => __('Buyer'),
            self::Seller => __('Seller'),
        };
    }
}
