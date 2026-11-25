<?php

namespace App\Enums;

/** The documents the platform issues. Each kind is its own numbered series. */
enum InvoiceKind: string
{
    case Purchase = 'purchase';
    case Commission = 'commission';
    case Subscription = 'subscription';
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case Fine = 'fine';
    case Featured = 'featured';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => __('Purchase receipt'),
            self::Commission => __('Commission invoice'),
            self::Subscription => __('Plan invoice'),
            self::Deposit => __('Deposit receipt'),
            self::Withdrawal => __('Withdrawal receipt'),
            self::Fine => __('Fine notice'),
            self::Featured => __('Featured listing invoice'),
        };
    }

    /** The number prefix: PUR-2026-000001. */
    public function series(): string
    {
        return match ($this) {
            self::Purchase => 'PUR',
            self::Commission => 'COM',
            self::Subscription => 'SUB',
            self::Deposit => 'DEP',
            self::Withdrawal => 'WDR',
            self::Fine => 'FIN',
            self::Featured => 'FEA',
        };
    }
}
