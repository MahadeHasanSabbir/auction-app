<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ filled($title ?? null) ? $title.' · ' : '' }}{{ config('app.name') }}</title>

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
    @livewireStyles
</head>
<body class="flex min-h-screen flex-col">
    <div class="flex flex-1 flex-col items-center px-4 py-10 sm:justify-center sm:py-16">
        <a href="{{ route('home') }}" aria-label="{{ __('Home') }}">
            <x-logo />
        </a>

        <div class="mt-8 w-full {{ $width }}">
            <x-card class="sm:p-8">
                {{ $slot }}
            </x-card>

            @isset($below)
                <div class="mt-6 text-center text-sm text-slate-600 dark:text-slate-400">
                    {{ $below }}
                </div>
            @endisset
        </div>
    </div>

    <x-flash />

    @livewireScripts
</body>
</html>
