<?php

namespace App\Enums;

enum FraudFlagStatus: string
{
    case Open = 'open';
    case Dismissed = 'dismissed';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Dismissed => __('Dismissed'),
            self::Confirmed => __('Confirmed'),
        };
    }

    /** The colour in the Filament panels. */
    public function color(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Dismissed => 'gray',
            self::Confirmed => 'danger',
        };
    }
}
