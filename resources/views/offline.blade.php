<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('You are offline') }} · {{ config('app.name') }}</title>
    {{-- Standalone on purpose: the service worker keeps one copy of this page for everyone. --}}
    <style>
        :root { color-scheme: light dark; --brand: #4f46e5; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1rem; font-family: Inter, ui-sans-serif, system-ui, sans-serif; background: #f8fafc; color: #0f172a; }
        main { max-width: 26rem; text-align: center; }
        h1 { font-size: 1.5rem; margin: 1rem 0 .5rem; }
        p { color: #475569; line-height: 1.6; margin: 0 0 1.5rem; }
        button { font: inherit; font-weight: 600; color: #fff; background: var(--brand); border: 0; border-radius: .5rem; padding: .625rem 1.25rem; cursor: pointer; }
        button:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
        svg { width: 3rem; height: 3rem; color: var(--brand); }
        @media (prefers-color-scheme: dark) { body { background: #020617; color: #f1f5f9; } p { color: #94a3b8; } }
    </style>
</head>
<body>
    <main>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 8.8a15 15 0 0 1 20 0M5 12.5a10 10 0 0 1 14 0M8.5 16.2a5 5 0 0 1 7 0M12 20h.01M3 3l18 18"/></svg>
        <h1>{{ __('You are offline') }}</h1>
        <p>{{ __('This page needs a connection. Auction prices and bids are only shown live, so check your internet and try again.') }}</p>
        <button type="button" onclick="location.reload()">{{ __('Try again') }}</button>
    </main>
</body>
</html>
