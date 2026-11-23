<?php

namespace App\Providers\Filament;

use App\Filament\Seller\Resources\Orders\Pages\ViewOrder;
use App\Filament\Support\BrandAvatarProvider;
use App\Filament\Support\PanelBranding;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\RequirePlan;
use App\Models\Company;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
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
        // The brand colour, logo and favicon, English only; the site fonts and
        // our compiled theme (resources/css/filament/panels/theme.css).
        return PanelBranding::apply($panel, 'Seller')
            ->id('seller')
            ->path('seller')
            ->tenant(Company::class, slugAttribute: 'slug')
            ->font('Inter', url: 'https://fonts.bunny.net/css?family=inter:400,500,600,700|space-grotesk:500,600,700&display=swap')
            ->viteTheme('resources/css/filament/panels/theme.css')
            ->sidebarCollapsibleOnDesktop()
            // Their bell: the team's alerts are in the notifications table (AppNotification).
            ->databaseNotifications()
            ->defaultAvatarProvider(BrandAvatarProvider::class)
            // A new company is sent to the billing page to choose its plan.
            ->tenantMiddleware([RequirePlan::class], isPersistent: true)
            ->renderHook(PanelsRenderHook::CONTENT_START, fn () => view('filament.notice-banner'))
            ->renderHook(PanelsRenderHook::CONTENT_START, fn () => view('filament.seller.status-banner'))
            // An open order refreshes by itself, so a delivery confirmation or a dispute shows without a reload.
            ->renderHook(PanelsRenderHook::CONTENT_START, fn () => new HtmlString('<div wire:poll.15s></div>'), scopes: ViewOrder::class)
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
                EnsureActiveAccount::class,
                Authenticate::class,
                EnsureEmailIsVerified::class,
            ]);
    }
}
