<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\UiAvatarsProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Initials avatars on the brand colour. Filament's default uses its darkest
 * gray, which disappears against the dark theme's background.
 */
final class BrandAvatarProvider extends UiAvatarsProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $brand = Color::convertToHex(FilamentColor::getColor('primary')[600] ?? Color::Blue[600]);

        return (string) preg_replace('/background=[^&]*/', 'background='.urlencode($brand), parent::get($record));
    }
}
