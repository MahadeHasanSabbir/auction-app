<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;
use NumberFormatter;
use Stringable;

/**
 * An amount of money in minor units (paisa, cents) of one currency.
 *
 * Immutable. All arithmetic is integer arithmetic; rates are basis points
 * (1% = 100 bps) and rounding is half away from zero. Mixing currencies throws.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(
        public int $amount,
        public string $currency,
    ) {}

    /** From an integer number of minor units, as stored in the database. */
    public static function minor(int $amount, ?string $currency = null): self
    {
        return new self($amount, $currency ?? self::defaultCurrency());
    }

    public static function zero(?string $currency = null): self
    {
        return self::minor(0, $currency);
    }

    /**
     * From a decimal string as typed by a person, for example "1250" or "12.50".
     * Rejects anything that is not a plain decimal with at most the currency's
     * number of fraction digits; never goes through a float.
     */
    public static function parse(string $decimal, ?string $currency = null): self
    {
        $decimals = self::decimals();
        $decimal = trim($decimal);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,'.max($decimals, 1).'}))?$/', $decimal, $m)) {
            throw new InvalidArgumentException("Not a valid amount: [{$decimal}].");
        }

        $fraction = $m[3] ?? '';

        if ($decimals === 0 && $fraction !== '') {
            throw new InvalidArgumentException("Not a valid amount: [{$decimal}].");
        }

        $digits = ltrim($m[2].str_pad($fraction, $decimals, '0'), '0');

        if (strlen($digits) > 18) {
            throw new InvalidArgumentException("Amount is too large: [{$decimal}].");
        }

        $minor = (int) ($digits === '' ? '0' : $digits);

        return self::minor($m[1] === '-' ? -$minor : $minor, $currency);
    }

    public function plus(self $other): self
    {
        return new self($this->amount + $this->same($other)->amount, $this->currency);
    }

    public function minus(self $other): self
    {
        return new self($this->amount - $this->same($other)->amount, $this->currency);
    }

    public function negated(): self
    {
        return new self(-$this->amount, $this->currency);
    }

    public function times(int $factor): self
    {
        return new self($this->amount * $factor, $this->currency);
    }

    /** The given share of this amount, in basis points, rounded half away from zero. */
    public function basisPoints(int $bps): self
    {
        $product = $this->amount * $bps;
        $rounded = intdiv(abs($product) + 5000, 10000);

        return new self($product < 0 ? -$rounded : $rounded, $this->currency);
    }

    /** The given share of this amount, in basis points, rounded towards zero. */
    public function basisPointsFloor(int $bps): self
    {
        return new self(intdiv($this->amount * $bps, 10000), $this->currency);
    }

    /**
     * This amount times $numerator / $denominator, rounded towards zero.
     * Exact for any size: the product goes through bcmath, never a float.
     */
    public function share(int $numerator, int $denominator): self
    {
        if ($denominator <= 0 || $numerator < 0) {
            throw new InvalidArgumentException('A share needs a non-negative numerator and a positive denominator.');
        }

        return new self((int) bcdiv(bcmul((string) $this->amount, (string) $numerator), (string) $denominator, 0), $this->currency);
    }

    public function clamp(?self $min, ?self $max): self
    {
        $value = $this;

        if ($min !== null && $value->lessThan($min)) {
            $value = $min;
        }

        if ($max !== null && $value->greaterThan($max)) {
            $value = $max;
        }

        return $value;
    }

    public function compare(self $other): int
    {
        return $this->amount <=> $this->same($other)->amount;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->compare($other) >= 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function lessThanOrEqual(self $other): bool
    {
        return $this->compare($other) <= 0;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    /** Plain decimal string without currency or grouping, for example "12.50". */
    public function toDecimal(): string
    {
        $decimals = self::decimals();

        if ($decimals === 0) {
            return (string) $this->amount;
        }

        $digits = str_pad((string) abs($this->amount), $decimals + 1, '0', STR_PAD_LEFT);

        return ($this->amount < 0 ? '-' : '')
            .substr($digits, 0, -$decimals).'.'.substr($digits, -$decimals);
    }

    /** Localised display string with the currency, for example "BDT 1,250.00". */
    public function format(?string $locale = null): string
    {
        $formatter = new NumberFormatter($locale ?? app()->getLocale(), NumberFormatter::CURRENCY);

        $formatted = $formatter->formatCurrency($this->amount / (10 ** self::decimals()), $this->currency);

        return $formatted === false ? $this->currency.' '.$this->toDecimal() : $formatted;
    }

    /** @return array{amount: int, currency: string, formatted: string} */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'formatted' => $this->format(),
        ];
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function same(self $other): self
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException(
                "Cannot combine {$this->currency} with {$other->currency}."
            );
        }

        return $other;
    }

    private static function defaultCurrency(): string
    {
        return (string) config('auction.currency', 'BDT');
    }

    private static function decimals(): int
    {
        return (int) config('auction.currency_decimals', 2);
    }
}
