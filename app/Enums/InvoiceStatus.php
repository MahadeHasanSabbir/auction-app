<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Paid => __('Paid'),
            self::Void => __('Void'),
        };
    }
}
