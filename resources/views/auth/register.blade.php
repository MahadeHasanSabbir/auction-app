<x-auth-layout :title="__('Create an account')" width="max-w-lg">
    <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-50">{{ __('Create an account') }}</h1>
    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ __('Choose how you want to use the marketplace.') }}</p>

    <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-5" x-data="{ type: @js(old('account_type', $accountType)) }">
        @csrf

        {{-- Buyer or seller. The choice decides which account is created; it cannot be changed later. --}}
        <fieldset>
            <legend class="sr-only">{{ __('Account type') }}</legend>

            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ([
                    'buyer' => ['gavel', __('I want to bid'), __('A personal account with a wallet. Bid on any auction.')],
                    'seller' => ['building', __('I want to sell'), __('A company account. List items and add your team.')],
                ] as $value => [$icon, $title, $text])
                    <label class="cursor-pointer">
                        <input type="radio" name="account_type" value="{{ $value }}" x-model="type" class="peer sr-only">
                        <span class="flex h-full flex-col rounded-lg border border-slate-300 p-4 peer-checked:border-brand-600 peer-checked:bg-brand-50 peer-checked:ring-1 peer-checked:ring-brand-600 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand-500 dark:border-slate-700 dark:peer-checked:border-brand-400 dark:peer-checked:bg-brand-950 dark:peer-checked:ring-brand-400">
                            <span class="flex items-center gap-2 font-semibold text-slate-900 dark:text-slate-50">
                                <x-app-icon :name="$icon" class="size-5 text-brand-700 dark:text-brand-300" />
                                {{ $title }}
                            </span>
                            <span class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ $text }}</span>
                        </span>
                    </label>
                @endforeach
            </div>

            @error('account_type')
                <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </fieldset>

        {{-- Company details, for sellers only. Disabled fields are not submitted. --}}
        <div x-show="type === 'seller'" x-cloak class="space-y-5">
            <x-field :label="__('Company name')" name="company_name" autocomplete="organization"
                     :hint="__('Shown to buyers on your auctions.')"
                     x-bind:disabled="type !== 'seller'" x-bind:required="type === 'seller'" />

            <x-field :label="__('Company phone (optional)')" name="phone" type="tel" autocomplete="tel"
                     x-bind:disabled="type !== 'seller'" />
        </div>

        <x-field :label="__('Your name')" name="name" required autocomplete="name" />

        <x-field :label="__('Email')" name="email" type="email" required autocomplete="username"
                 :hint="__('We send a code to this address to confirm it.')" />

        <div class="grid gap-5 sm:grid-cols-2">
            <x-field :label="__('Password')" name="password" type="password" required autocomplete="new-password" />
            <x-field :label="__('Repeat password')" name="password_confirmation" type="password" required autocomplete="new-password" />
        </div>

        <p class="text-xs text-slate-500 dark:text-slate-400">
            {!! __('By creating an account you agree to the :terms and the :privacy.', [
                'terms' => '<a href="'.e(route('pages.terms')).'" class="font-medium text-brand-700 underline dark:text-brand-300" target="_blank">'.e(__('Terms of use')).'</a>',
                'privacy' => '<a href="'.e(route('pages.privacy')).'" class="font-medium text-brand-700 underline dark:text-brand-300" target="_blank">'.e(__('Privacy policy')).'</a>',
            ]) !!}
        </p>

        <x-button class="w-full" size="lg">
            <span x-show="type !== 'seller'">{{ __('Create buyer account') }}</span>
            <span x-show="type === 'seller'" x-cloak>{{ __('Create company account') }}</span>
        </x-button>

        <p x-show="type === 'seller'" x-cloak class="text-center text-xs text-slate-500 dark:text-slate-400">
            {{ __('Your company can list auctions once the platform has verified it.') }}
        </p>

        <x-google-button :label="__('Sign up with Google')" x-show="type !== 'seller'" />
    </form>

    <x-slot:below>
        {{ __('Already have an account?') }}
        <a href="{{ route('login') }}" class="font-semibold text-brand-700 hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">{{ __('Sign in') }}</a>
    </x-slot:below>
</x-auth-layout>
