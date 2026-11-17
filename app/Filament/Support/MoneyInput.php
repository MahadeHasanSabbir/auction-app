<?php

namespace App\Filament\Support;

use App\Support\Money;
use Filament\Forms\Components\TextInput;

/**
 * A text field for a money column. The model holds a Money; the field shows
 * and takes a decimal amount ("1500.50") and saves it back as a Money.
 */
final class MoneyInput
{
    public static function make(string $name): TextInput
    {
        return TextInput::make($name)
            ->inputMode('decimal')
            ->suffix((string) config('auction.currency'))
            ->rule('regex:/^\d{1,12}(\.\d{1,2})?$/')
            ->formatStateUsing(fn (mixed $state): ?string => $state instanceof Money ? $state->toDecimal() : ($state === null ? null : (string) $state))
            ->dehydrateStateUsing(fn (mixed $state): ?Money => filled($state) ? Money::parse((string) $state) : null);
    }
}
