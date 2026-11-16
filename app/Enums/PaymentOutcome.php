<?php

namespace App\Enums;

/** What a payment gateway reports about one payment, once verified. */
enum PaymentOutcome: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Succeeded => __('Paid'),
            self::Failed => __('Failed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function depositStatus(): DepositStatus
    {
        return match ($this) {
            self::Succeeded => DepositStatus::Succeeded,
            self::Failed => DepositStatus::Failed,
            self::Cancelled => DepositStatus::Cancelled,
        };
    }
}
