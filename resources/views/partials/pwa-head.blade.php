{{-- Installable app: manifest, icons, the browser bar colour (the brand colour, not the owner's logo). --}}
@inject('pwaBranding', 'App\Services\Branding\Branding')
<link rel="manifest" href="{{ route('pwa.manifest') }}">
<meta name="theme-color" content="{{ $pwaBranding->color() ?: '#4f46e5' }}">
<meta name="application-name" content="{{ config('app.name') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
<link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
