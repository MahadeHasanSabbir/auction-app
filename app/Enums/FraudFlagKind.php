<?php

namespace App\Enums;

/** Why the platform flagged a bid or an account for review. */
enum FraudFlagKind: string
{
    case SharedIp = 'shared_ip';

    public function label(): string
    {
        return match ($this) {
            self::SharedIp => __('Bidder shares an IP address with the seller'),
        };
    }
}
