<?php

namespace App\Enums;

enum HoldStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Captured = 'captured';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Released => __('Released'),
            self::Captured => __('Captured'),
        };
    }
}
