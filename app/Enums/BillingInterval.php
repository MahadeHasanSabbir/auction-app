<?php

namespace App\Enums;

use Carbon\CarbonInterface;

enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /** The end of a period that starts at $start. */
    public function endFrom(CarbonInterface $start): CarbonInterface
    {
        return match ($this) {
            self::Monthly => $start->addMonthNoOverflow(),
            self::Yearly => $start->addYearNoOverflow(),
        };
    }

    /** Months in one period, to compare prices across intervals. */
    public function months(): int
    {
        return $this === self::Yearly ? 12 : 1;
    }

    public function label(): string
    {
        return match ($this) {
            self::Monthly => __('Monthly'),
            self::Yearly => __('Yearly'),
        };
    }
}
