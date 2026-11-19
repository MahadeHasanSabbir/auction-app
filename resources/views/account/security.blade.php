<x-site-layout :title="__('Sign-in and security')">
    <x-account.shell :title="__('Sign-in and security')" :description="__('Your email address, password and the browsers that may skip the sign-in code.')">
        {{-- Email --}}
        <x-card>
            <h2 class="font-sans text-base font-semibold tracking-normal text-slate-900 dark:text-slate-50">{{ __('Email address') }}</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ __('Sign-in codes and notifications go to this address. A new address has to be confirmed with a code before you can continue.') }}</p>

            <form method="POST" action="{{ route('profile.email') }}" class="mt-5 flex flex-wrap items-start gap-3">
                @csrf
                @method('PATCH')

                <div class="min-w-0 flex-1 sm:max-w-sm">
                    <x-field :label="__('Email')" name="email" type="email" :value="$user->email" bag="updateEmail" required autocomplete="email" />
                </div>
                <x-button variant="secondary" class="mt-7">{{ __('Change email') }}</x-button>
            </form>
        </x-card>

        {{-- Password --}}
        <x-card>
            <h2 class="font-sans text-base font-semibold tracking-normal text-slate-900 dark:text-slate-50">{{ __('Password') }}</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ __('Changing your password also removes every trusted browser.') }}</p>

            <form method="POST" action="{{ route('password.update') }}" class="mt-5 space-y-5 sm:max-w-sm">
                @csrf
                @method('PUT')

                <x-field :label="__('Current password')" name="current_password" type="password" bag="updatePassword" required autocomplete="current-password" />
                <x-field :label="__('New password')" name="password" id="new_password" type="password" bag="updatePassword" required autocomplete="new-password" />
                <x-field :label="__('Repeat new password')" name="password_confirmation" type="password" bag="updatePassword" required autocomplete="new-password" />

                <x-button variant="secondary">{{ __('Change password') }}</x-button>
            </form>
        </x-card>

        {{-- Trusted devices --}}
        <x-card>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="font-sans text-base font-semibold tracking-normal text-slate-900 dark:text-slate-50">{{ __('Trusted browsers') }}</h2>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ __('These browsers sign in with the password alone, without an emailed code.') }}</p>
                </div>

                @if ($devices->isNotEmpty())
                    <form method="POST" action="{{ route('profile.devices') }}">
                        @csrf
                        @method('DELETE')
                        <x-button variant="secondary" size="sm">{{ __('Remove all') }}</x-button>
                    </form>
                @endif
            </div>

            @if ($devices->isEmpty())
                <p class="mt-4 rounded-md bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:bg-slate-800/60 dark:text-slate-400">{{ __('No trusted browsers. Every sign-in asks for a code.') }}</p>
            @else
                <ul class="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($devices as $device)
                        <li class="py-3 text-sm">
                            <p class="truncate font-medium text-slate-900 dark:text-slate-100" title="{{ $device->user_agent }}">{{ $device->user_agent ?: __('Unknown browser') }}</p>
                            <p class="mt-0.5 text-slate-500 dark:text-slate-400">
                                @if ($device->last_used_at)
                                    {{ __('Last used') }} <x-datetime :value="$device->last_used_at" />
                                    @if ($device->ip_address) · {{ $device->ip_address }} @endif ·
                                @endif
                                {{ __('trusted until') }} <x-datetime :value="$device->expires_at" format="j M Y" />
                            </p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>

        {{-- Sign-in history --}}
        <x-card>
            <h2 class="font-sans text-base font-semibold tracking-normal text-slate-900 dark:text-slate-50">{{ __('Recent sign-ins') }}</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ __('If you do not recognise one, change your password and remove your trusted browsers.') }}</p>

            @if ($logins->isEmpty())
                <p class="mt-4 rounded-md bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:bg-slate-800/60 dark:text-slate-400">{{ __('No sign-ins recorded yet.') }}</p>
            @else
                <ul class="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($logins as $login)
                        <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 py-3 text-sm">
                            <span>
                                <span class="font-medium text-slate-900 dark:text-slate-100">{{ $login->browser() }}</span>
                                <span class="text-slate-500 dark:text-slate-400">· {{ $login->ip_address ?? '—' }} · {{ $login->succeeded ? $login->method->label() : __('Wrong password') }}</span>
                            </span>
                            <span @class(['text-slate-500 dark:text-slate-400', 'font-medium text-red-700 dark:text-red-300' => ! $login->succeeded])>
                                <x-datetime :value="$login->created_at" />
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>

        {{-- Closing the account: buyers only. Company and staff accounts are managed by their administrators. --}}
        @if ($user->isBuyer())
            <x-card class="border-red-200 dark:border-red-900/60">
                <h2 class="font-sans text-base font-semibold tracking-normal text-slate-900 dark:text-slate-50">{{ __('Close account') }}</h2>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    {{ __('You will be signed out and will not be able to sign in again. Your wallet must be empty and you must have no open bids. Past bids and orders stay on record.') }}
                </p>

                <form method="POST" action="{{ route('profile.destroy') }}" class="mt-5 flex flex-wrap items-start gap-3"
                      x-data @submit="if (! confirm(@js(__('Close your account for good?')))) $event.preventDefault()">
                    @csrf
                    @method('DELETE')

                    <div class="min-w-0 flex-1 sm:max-w-sm">
                        <x-field :label="__('Your password')" name="password" id="delete_password" type="password" bag="userDeletion" required autocomplete="current-password" />
                    </div>
                    <x-button variant="danger" class="mt-7">{{ __('Close my account') }}</x-button>
                </form>
            </x-card>
        @endif
    </x-account.shell>
</x-site-layout>
