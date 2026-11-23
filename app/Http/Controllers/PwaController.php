<?php

namespace App\Http\Controllers;

use App\Services\Branding\Branding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** What a browser needs to install the site as an app: the manifest and the offline page. */
class PwaController extends Controller
{
    public function manifest(Branding $branding): JsonResponse
    {
        $name = (string) config('app.name');

        return response()->json([
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'description' => __('Timed auctions from verified sellers, with every winning bid held safely in escrow.'),
            'lang' => app()->getLocale(),
            'id' => '/',
            'start_url' => '/?source=pwa',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => $branding->color() ?: '#4f46e5',
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => __('Auctions'), 'url' => route('auctions.index', [], false), 'icons' => [['src' => '/icons/icon-192.png', 'sizes' => '192x192']]],
                ['name' => __('My bids'), 'url' => route('bids', [], false), 'icons' => [['src' => '/icons/icon-192.png', 'sizes' => '192x192']]],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json'])->setPublic()->setMaxAge(3600);
    }

    /** Shown by the service worker when a page cannot be loaded. Public and the same for everyone, so it can be cached. */
    public function offline(): View|Response
    {
        return response()->view('offline')->setPublic()->setMaxAge(3600);
    }
}
