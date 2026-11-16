<?php

namespace App\Enums;

enum ProductCondition: string
{
    case New = 'new';
    case LikeNew = 'like_new';
    case Used = 'used';
    case Refurbished = 'refurbished';

    public function label(): string
    {
        return match ($this) {
            self::New => __('New'),
            self::LikeNew => __('Like new'),
            self::Used => __('Used'),
            self::Refurbished => __('Refurbished'),
        };
    }
}
