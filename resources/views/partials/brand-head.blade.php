{{-- Platform branding (admin → Branding): favicon and the brand colour scale. --}}
@inject('branding', 'App\Services\Branding\Branding')
@if ($branding->faviconUrl())
    <link rel="icon" type="image/png" href="{{ $branding->faviconUrl() }}">
@else
    <link rel="icon" href="{{ asset('favicon.ico') }}">
@endif
@if ($branding->css())
    <style>{!! $branding->css() !!}</style>
@endif
@if (app()->getLocale() === 'bn')
    <link href="https://fonts.bunny.net/css?family=hind-siliguri:400,500,600,700&display=swap" rel="stylesheet">
    <style>:root { --font-sans: 'Inter', 'Hind Siliguri', ui-sans-serif, system-ui, sans-serif; --font-display: 'Space Grotesk', 'Hind Siliguri', 'Inter', ui-sans-serif, system-ui, sans-serif; }</style>
@endif
