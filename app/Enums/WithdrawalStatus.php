<?php

namespace App\Enums;

enum WithdrawalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Awaiting review'),
            self::Approved => __('Approved'),
            self::Paid => __('Paid'),
            self::Rejected => __('Rejected'),
            self::Cancelled => __('Cancelled'),
        };
    }
}
