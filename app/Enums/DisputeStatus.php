<?php

namespace App\Enums;

enum DisputeStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Resolved => __('Resolved'),
            self::Withdrawn => __('Withdrawn'),
        };
    }
}
