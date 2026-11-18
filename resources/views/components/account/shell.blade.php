@props(['title', 'description' => null])

{{-- The frame of the signed-in account pages: a heading, section links and the page. --}}
@php
    $user = auth()->user();

    $links = array_filter([
        $user->isBuyer() ? ['url' => route('dashboard'), 'label' => __('Overview'), 'icon' => 'home', 'active' => request()->routeIs('dashboard')] : null,
        $user->isBuyer() ? ['url' => route('bids'), 'label' => __('My bids'), 'icon' => 'gavel', 'active' => request()->routeIs('bids')] : null,
        $user->isBuyer() ? ['url' => route('orders'), 'label' => __('My orders'), 'icon' => 'truck', 'active' => request()->routeIs('orders', 'orders.*')] : null,
        $user->isBuyer() ? ['url' => route('wallet'), 'label' => __('Wallet'), 'icon' => 'wallet', 'active' => request()->routeIs('wallet', 'wallet.*')] : null,
        ['url' => route('profile.edit'), 'label' => __('Profile'), 'icon' => 'user', 'active' => request()->routeIs('profile.edit')],
        ['url' => route('profile.security'), 'label' => __('Sign-in and security'), 'icon' => 'lock', 'active' => request()->routeIs('profile.security')],
    ]);
@endphp

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <div class="grid gap-8 lg:grid-cols-[15rem_1fr]">
        <nav aria-label="{{ __('Account') }}" class="flex gap-1 overflow-x-auto lg:flex-col lg:overflow-visible">
            @foreach ($links as $link)
                <a href="{{ $link['url'] }}" @if ($link['active']) aria-current="page" @endif
                   @class([
                       'inline-flex shrink-0 items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium',
                       'bg-brand-50 text-brand-800 dark:bg-brand-950 dark:text-brand-200' => $link['active'],
                       'text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800' => ! $link['active'],
                   ])>
                    <x-app-icon :name="$link['icon']" class="size-4" />
                    {{ $link['label'] }}
                </a>
            @endforeach
        </nav>

        <div class="min-w-0">
            <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-50">{{ $title }}</h1>
            @if ($description)
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ $description }}</p>
            @endif

            <div class="mt-6 space-y-6">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
