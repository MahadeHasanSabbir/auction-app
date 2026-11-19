<?php

namespace App\Filament\Support;

use App\Http\Middleware\UseDefaultLocale;
use App\Services\Branding\Branding;
use Filament\Panel;
use Filament\Support\Colors\Color;

/**
 * What both panels share: the platform branding (admin → Branding; read when
 * a page renders, not at boot) and the default language, since the panels
 * are not translated.
 */
final class PanelBranding
{
    public static function apply(Panel $panel, string $suffix): Panel
    {
        return $panel
            ->brandName(fn () => config('app.name').' '.$suffix)
            ->brandLogo(fn () => app(Branding::class)->logoUrl())
            ->brandLogoHeight('2rem')
            ->favicon(fn () => app(Branding::class)->faviconUrl())
            ->colors(fn () => [
                'primary' => app(Branding::class)->palette() ?? Color::Blue,
                'gray' => Color::Slate,
            ])
            ->middleware([UseDefaultLocale::class], isPersistent: true);
    }
}
