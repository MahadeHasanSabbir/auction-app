<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Reads a BIGINT minor-unit column as a Money object in the marketplace
 * currency. Accepts a Money or an integer number of minor units when writing;
 * anything else (a float, a decimal string) is refused.
 *
 * @implements CastsAttributes<Money, mixed>
 */
final class MoneyCast implements CastsAttributes
{
    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        return $value === null ? null : Money::minor((int) $value);
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return match (true) {
            $value === null => null,
            $value instanceof Money => $value->amount,
            is_int($value) => $value,
            default => throw new InvalidArgumentException(
                "[{$key}] must be a Money or an integer number of minor units."
            ),
        };
    }
}
