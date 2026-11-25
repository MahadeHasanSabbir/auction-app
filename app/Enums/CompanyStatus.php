<?php

namespace App\Enums;

enum CompanyStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Awaiting verification'),
            self::Active => __('Active'),
            self::Suspended => __('Suspended'),
        };
    }
}
