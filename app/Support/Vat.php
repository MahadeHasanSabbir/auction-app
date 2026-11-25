<?php

namespace App\Support;

/**
 * VAT on the platform's own fees. Fees and plan prices include it
 * (auction.tax.vat_bps); split() tells the VAT part apart so the ledger
 * can move it to the tax-payable account.
 */
final class Vat
{
    public static function rate(): int
    {
        return max(0, (int) config('auction.tax.vat_bps'));
    }

    /**
     * The VAT included in a gross amount: gross × rate / (1 + rate), rounded
     * half up to the minor unit.
     */
    public static function included(Money $gross, ?int $bps = null): Money
    {
        $bps ??= self::rate();

        if ($bps === 0 || ! $gross->isPositive()) {
            return Money::zero($gross->currency);
        }

        // Round half up: (2·gross·bps + denominator) / (2·denominator).
        $denominator = 10_000 + $bps;

        return Money::minor(
            (int) bcdiv(bcadd(bcmul((string) (2 * $gross->amount), (string) $bps), (string) $denominator), (string) (2 * $denominator), 0),
            $gross->currency,
        );
    }

    /** @return array{0: Money, 1: Money} the amount without VAT, and the VAT */
    public static function split(Money $gross, ?int $bps = null): array
    {
        $tax = self::included($gross, $bps);

        return [$gross->minus($tax), $tax];
    }
}
