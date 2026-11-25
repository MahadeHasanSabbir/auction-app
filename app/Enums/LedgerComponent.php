<?php

namespace App\Enums;

/** Which part of a transaction a ledger entry represents. */
enum LedgerComponent: string
{
    case Principal = 'principal';
    case GatewayCharge = 'gateway_charge';
    case ServiceCharge = 'service_charge';
    case Commission = 'commission';
    case Tax = 'tax';

    public function label(): string
    {
        return match ($this) {
            self::Principal => __('Principal'),
            self::GatewayCharge => __('Gateway charge'),
            self::ServiceCharge => __('Service charge'),
            self::Commission => __('Commission'),
            self::Tax => __('Tax'),
        };
    }
}
