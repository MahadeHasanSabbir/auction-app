<?php

namespace App\Services\Ledger;

use App\Support\Money;
use RuntimeException;

/**
 * A wallet cannot cover a debit or a hold from its available balance
 * (balance minus what is on hold).
 */
final class InsufficientFundsException extends RuntimeException
{
    public function __construct(
        public readonly int $walletId,
        public readonly Money $available,
        public readonly Money $requested,
    ) {
        parent::__construct(
            "Wallet {$walletId} has {$available->toDecimal()} available, {$requested->toDecimal()} is needed."
        );
    }
}
