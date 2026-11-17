<?php

namespace App\Providers\Filament;

use App\Filament\Support\BrandAvatarProvider;
use App\Http\Middleware\RequirePlan;
use App\Models\Company;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The seller panel at /seller/{company}, for company owners and members.
 *
 * The company is the Filament tenant: every resource in this panel is scoped
 * to it, and User::canAccessTenant() refuses any company but the user's own.
 */
class SellerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('seller')
            ->path('seller')
            ->tenant(Company::class, slugAttribute: 'slug')
            ->brandName(config('app.name').' Seller')
            // The site's brand blue, slate neutrals and fonts, and our compiled
            // theme (resources/css/filament/panels/theme.css).
            ->colors([
                'primary' => Color::Blue,
                'gray' => Color::Slate,
            ])
            ->font('Inter', url: 'https://fonts.bunny.net/css?family=inter:400,500,600,700|space-grotesk:500,600,700&display=swap')
            ->viteTheme('resources/css/filament/panels/theme.css')
            ->sidebarCollapsibleOnDesktop()
            ->defaultAvatarProvider(BrandAvatarProvider::class)
            // A new company is sent to the billing page to choose its plan.
            ->tenantMiddleware([RequirePlan::class], isPersistent: true)
            ->renderHook(PanelsRenderHook::CONTENT_START, fn () => view('filament.seller.status-banner'))
            ->discoverResources(in: app_path('Filament/Seller/Resources'), for: 'App\Filament\Seller\Resources')
            ->discoverPages(in: app_path('Filament/Seller/Pages'), for: 'App\Filament\Seller\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Seller/Widgets'), for: 'App\Filament\Seller\Widgets')
            ->widgets([
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // No panel login page: guests are sent to the site sign-in, so every
            // account passes the same emailed-code step.
            ->authMiddleware([
                Authenticate::class,
                EnsureEmailIsVerified::class,
            ]);
    }
}
