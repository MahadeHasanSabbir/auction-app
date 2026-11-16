<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => __('Trial'),
            self::Active => __('Active'),
            self::PastDue => __('Payment overdue'),
            self::Cancelled => __('Cancelled'),
            self::Expired => __('Expired'),
        };
    }

    /** The company may create new listings and invite members. */
    public function allowsSelling(): bool
    {
        return $this === self::Trialing || $this === self::Active;
    }
}
