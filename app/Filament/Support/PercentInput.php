<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;

/**
 * A rate stored as basis points and typed as a percentage with at most two
 * decimals ("7.5" is 750 bps).
 */
final class PercentInput
{
    public static function make(string $name): TextInput
    {
        return TextInput::make($name)
            ->suffix('%')
            ->inputMode('decimal')
            ->rule('regex:/^\d{1,3}(\.\d{1,2})?$/')
            ->formatStateUsing(fn (mixed $state): ?string => $state === null ? null : self::format((int) $state))
            ->dehydrateStateUsing(fn (mixed $state): int => self::parse((string) $state));
    }

    /** 750 → "7.5" */
    public static function format(int $bps): string
    {
        return rtrim(rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.');
    }

    /** "7.5" → 750, without floating point. */
    public static function parse(string $percent): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($percent), 2), 2, '');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
