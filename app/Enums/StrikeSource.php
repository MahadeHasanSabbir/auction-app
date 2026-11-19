<?php

namespace App\Enums;

/** What a strike against a buyer was given for. */
enum StrikeSource: string
{
    case Manual = 'manual';
    case Retraction = 'retraction';
    case UnpaidOrder = 'unpaid_order';
    case FraudFlag = 'fraud_flag';

    public function label(): string
    {
        return match ($this) {
            self::Manual => __('Given by staff'),
            self::Retraction => __('Retracted bids'),
            self::UnpaidOrder => __('No delivery address for an order'),
            self::FraudFlag => __('Confirmed flag'),
        };
    }
}
