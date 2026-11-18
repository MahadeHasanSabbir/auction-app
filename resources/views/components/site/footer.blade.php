<footer class="mt-16 border-t border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
    <div class="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:grid-cols-2 sm:px-6 lg:grid-cols-4 lg:px-8">
        <div class="sm:col-span-2">
            <x-logo />
            <p class="mt-3 max-w-sm text-sm text-slate-600 dark:text-slate-400">
                {{ __('A marketplace where verified sellers run timed auctions and every winning bid is paid safely through escrow.') }}
            </p>
        </div>

        <nav aria-label="{{ __('Marketplace') }}">
            <h2 class="font-sans text-sm font-semibold tracking-normal text-slate-900 dark:text-slate-100">{{ __('Marketplace') }}</h2>
            <ul class="mt-3 space-y-2 text-sm text-slate-600 dark:text-slate-400">
                <li><a class="hover:text-brand-700 dark:hover:text-brand-300" href="{{ route('auctions.index') }}">{{ __('Browse auctions') }}</a></li>
                <li><a class="hover:text-brand-700 dark:hover:text-brand-300" href="{{ route('auctions.index', ['status' => 'upcoming']) }}">{{ __('Opening soon') }}</a></li>
                <li><a class="hover:text-brand-700 dark:hover:text-brand-300" href="{{ route('about') }}">{{ __('How it works') }}</a></li>
                <li><a class="hover:text-brand-700 dark:hover:text-brand-300" href="{{ route('notices') }}">{{ __('Notices') }}</a></li>
                <li><a class="hover:text-brand-700 dark:hover:text-brand-300" href="{{ route('contact') }}">{{ __('Contact us') }}</a></li>
            </ul>
        </nav>

        <nav aria-label="{{ __('Account') }}">
            <h2 class="font-sans text-sm font-semibold tracking-normal text-slate-900 dark:text-slate-100">{{ __('Account') }}</h2>
            <ul class="mt-3 space-y-2 text-sm text-slate-600 dark:text-slate-400">
                @auth
                    <li><a class="hover:text-brand-700 dark:hover:text-brand-300" href="{{ route('profile.edit') }}">{{ __('Profile') }}</a></li>
                @else
                    <li><a class="hover:text-brand-700 dark:hover:text-brand-300" href="{{ route('login') }}">{{ __('Sign in') }}</a></li>
                    <li><a class="hover:text-brand-700 dark:hover:text-brand-300" href="{{ route('register') }}">{{ __('Start bidding') }}</a></li>
                    <li><a class="hover:text-brand-700 dark:hover:text-brand-300" href="{{ route('register', ['type' => 'seller']) }}">{{ __('Sell with us') }}</a></li>
                @endauth
            </ul>
        </nav>
    </div>

    <div class="border-t border-slate-200 dark:border-slate-800">
        <p class="mx-auto max-w-7xl px-4 py-4 text-xs text-slate-500 sm:px-6 lg:px-8">
            &copy; {{ now()->year }} {{ config('app.name') }}. {{ __('All rights reserved.') }}
        </p>
    </div>
</footer>
