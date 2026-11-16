<?php

namespace App\Enums;

/** The platform-owned ledger accounts. Each one is a wallet row of type system. */
enum SystemAccount: string
{
    case GatewayClearing = 'gateway_clearing';
    case GatewayCharges = 'gateway_charges';
    case ServiceRevenue = 'service_revenue';
    case CommissionRevenue = 'commission_revenue';
    case SubscriptionRevenue = 'subscription_revenue';
    case TaxPayable = 'tax_payable';
    case Promotions = 'promotions';
    case Escrow = 'escrow';
    case PayoutClearing = 'payout_clearing';

    public function label(): string
    {
        return match ($this) {
            self::GatewayClearing => __('Gateway clearing'),
            self::GatewayCharges => __('Gateway charges'),
            self::ServiceRevenue => __('Service revenue'),
            self::CommissionRevenue => __('Commission revenue'),
            self::SubscriptionRevenue => __('Subscription revenue'),
            self::TaxPayable => __('Tax payable'),
            self::Promotions => __('Promotions'),
            self::Escrow => __('Escrow'),
            self::PayoutClearing => __('Payout clearing'),
        };
    }
}
