<?php

namespace App\Enums;

enum LedgerTransactionType: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case SaleCapture = 'sale_capture';
    case EscrowRelease = 'escrow_release';
    case Refund = 'refund';
    case SubscriptionPayment = 'subscription_payment';
    case Promotion = 'promotion';
    case Fine = 'fine';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => __('Deposit'),
            self::Withdrawal => __('Withdrawal'),
            self::SaleCapture => __('Sale captured into escrow'),
            self::EscrowRelease => __('Escrow released'),
            self::Refund => __('Refund'),
            self::SubscriptionPayment => __('Plan payment'),
            self::Promotion => __('Promotion credit'),
            self::Fine => __('Fine'),
            self::Adjustment => __('Adjustment'),
        };
    }
}
