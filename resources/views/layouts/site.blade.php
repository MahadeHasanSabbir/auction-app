<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ filled($title ?? null) ? $title.' · ' : '' }}{{ config('app.name') }}</title>

    @include('partials.seo-head')

    {{-- Apply the saved or system theme before the first paint, so the page never flashes. --}}
    <script>
        (() => {
            const saved = localStorage.getItem('theme');
            const dark = saved ? saved === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|space-grotesk:500,600,700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.brand-head')
    @livewireStyles
</head>
<body class="flex min-h-screen flex-col">
    <a href="#content" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:shadow-lg dark:focus:bg-slate-900">
        {{ __('Skip to content') }}
    </a>

    <x-site.header />

    <x-site.notice-banner />

    <main id="content" class="flex-1">
        {{ $slot }}
    </main>

    <x-site.footer />

    <x-flash />

    {{-- Words the live updates write into the page (resources/js/live.js). --}}
    <script>
        window.liveText = @js([
            'bids' => ['zero' => __('No bids yet'), 'one' => __(':count bid'), 'other' => __(':count bids')],
            'currentBid' => __('Current bid'),
            'startingPrice' => __('Starting price'),
            'you' => __('You'),
            'viewers' => __(':count watching now'),
            'extended' => __('A late bid extended the auction.'),
        ]);
    </script>

    @auth
        {{-- The signed-in user's private channel: outbid and won toasts, the live balance. --}}
        <div x-data="liveAccount({{ auth()->id() }})" hidden></div>
    @endauth

    @livewireScripts
</body>
</html>
