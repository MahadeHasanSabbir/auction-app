<?php

namespace App\Enums;

enum KycStatus: string
{
    case NotRequired = 'not_required';
    case Requested = 'requested';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => __('Not required'),
            self::Requested => __('Documents requested'),
            self::Submitted => __('Under review'),
            self::Approved => __('Verified'),
            self::Rejected => __('Rejected'),
        };
    }

    /** True when nothing is outstanding: never asked for, or approved. */
    public function isCleared(): bool
    {
        return $this === self::NotRequired || $this === self::Approved;
    }
}
