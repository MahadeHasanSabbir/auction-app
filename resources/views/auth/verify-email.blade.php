<x-auth-layout :title="__('Confirm your email')">
    <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-50">{{ __('Confirm your email') }}</h1>

    <div class="mt-2">
        <x-otp-form :action="route('verification.verify')" :resend-action="route('verification.send')"
                    :email="$email" :resend-in="$resendIn" :submit-label="__('Confirm email')" />
    </div>

    <x-slot:below>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="font-semibold text-brand-700 hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">{{ __('Sign out') }}</button>
        </form>
    </x-slot:below>
</x-auth-layout>
