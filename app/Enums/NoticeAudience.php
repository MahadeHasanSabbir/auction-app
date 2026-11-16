<?php

namespace App\Enums;

enum NoticeAudience: string
{
    case All = 'all';
    case Buyers = 'buyers';
    case Sellers = 'sellers';

    public function label(): string
    {
        return match ($this) {
            self::All => __('Everyone'),
            self::Buyers => __('Buyers'),
            self::Sellers => __('Sellers'),
        };
    }
}
