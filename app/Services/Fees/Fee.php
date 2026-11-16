<?php

namespace App\Services\Fees;

use App\Models\FeeRule;
use App\Support\Money;

/**
 * A charge worked out from a fee rule. With no rule in force the charge is
 * zero and $rule is null.
 */
final readonly class Fee
{
    public function __construct(
        public Money $amount,
        public ?FeeRule $rule = null,
    ) {}

    public static function none(string $currency): self
    {
        return new self(Money::zero($currency));
    }

    /** percent of the amount plus the fixed part, clamped between min and max, never negative. */
    public static function calculate(Money $amount, int $percentBps, Money $fixed, ?Money $min, ?Money $max): Money
    {
        $fee = $amount->basisPoints($percentBps)->plus($fixed)->clamp($min, $max);

        return $fee->isNegative() ? Money::zero($amount->currency) : $fee;
    }
}
