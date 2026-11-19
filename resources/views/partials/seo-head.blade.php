{{--
    Meta tags for search engines and link previews. Pages pass description,
    image (absolute URL), canonical and noindex through the layout.
--}}
@php
    $pageTitle = (filled($title ?? null) ? $title.' · ' : '').config('app.name');
    $pageDescription = \Illuminate\Support\Str::limit(trim(strip_tags((string) ($description ?? null))) ?: __('A marketplace where verified sellers run timed auctions and every winning bid is paid safely through escrow.'), 160);
    $pageUrl = $canonical ?? url()->current();
    $pageImage = $image ?? app(\App\Services\Branding\Branding::class)->logoUrl();
@endphp
<meta name="description" content="{{ $pageDescription }}">
@if ($noindex ?? false)
    <meta name="robots" content="noindex, nofollow">
@else
    <link rel="canonical" href="{{ $pageUrl }}">
@endif
<meta property="og:site_name" content="{{ config('app.name') }}">
<meta property="og:type" content="website">
<meta property="og:title" content="{{ $pageTitle }}">
<meta property="og:description" content="{{ $pageDescription }}">
<meta property="og:url" content="{{ $pageUrl }}">
<meta property="og:locale" content="{{ app()->getLocale() === 'bn' ? 'bn_BD' : 'en_US' }}">
@if ($pageImage)
    <meta property="og:image" content="{{ $pageImage }}">
    <meta name="twitter:card" content="summary_large_image">
@else
    <meta name="twitter:card" content="summary">
@endif
