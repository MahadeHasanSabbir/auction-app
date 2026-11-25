{{--
    The frame of every error page. Self-contained on purpose: no Vite build,
    session, database or Livewire, so it still renders when one of those is
    what failed. It never shows the exception: the details go to the log only.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <style>
        :root { --bg: #f8fafc; --card: #ffffff; --border: #e2e8f0; --text: #0f172a; --muted: #475569; --brand: #2f4fd8; --brand-text: #ffffff; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #020617; --card: #0f172a; --border: #1e293b; --text: #f8fafc; --muted: #94a3b8; --brand: #4f6ff0; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px;
               background: var(--bg); color: var(--text); font: 16px/1.5 Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { width: 100%; max-width: 28rem; text-align: center; }
        .brand { display: inline-flex; align-items: center; gap: 8px; font-weight: 700; font-size: 18px; color: var(--text); text-decoration: none; }
        .mark { display: inline-flex; width: 32px; height: 32px; align-items: center; justify-content: center; border-radius: 6px; background: var(--brand); color: var(--brand-text); }
        .card { margin-top: 32px; padding: 32px 24px; border: 1px solid var(--border); border-radius: 8px; background: var(--card); }
        .code { margin: 0; font-size: 14px; font-weight: 600; letter-spacing: .08em; color: var(--brand); }
        h1 { margin: 8px 0 0; font-size: 24px; line-height: 1.25; }
        p { margin: 12px 0 0; color: var(--muted); }
        .actions { margin-top: 24px; display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; }
        .button { display: inline-block; padding: 8px 16px; border-radius: 6px; font-size: 14px; font-weight: 600; text-decoration: none;
                  background: var(--brand); color: var(--brand-text); border: 1px solid var(--brand); cursor: pointer; }
        .button.secondary { background: transparent; color: var(--text); border-color: var(--border); }
    </style>
</head>
<body>
    <main>
        <a class="brand" href="{{ url('/') }}">
            <span class="mark" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.3 12.1 19.1 9.3 12.7 2.9 9.9 5.7Zm-3.2-3.2L5 17m-2 4h9"/></svg>
            </span>
            {{ config('app.name') }}
        </a>

        <div class="card">
            <p class="code">@yield('code')</p>
            <h1>@yield('title')</h1>
            <p>@yield('message')</p>

            <div class="actions">
                @section('actions')
                    <a class="button" href="{{ url('/') }}">{{ __('Go to the home page') }}</a>
                    <a class="button secondary" href="javascript:history.back()">{{ __('Go back') }}</a>
                @show
            </div>
        </div>
    </main>
</body>
</html>
