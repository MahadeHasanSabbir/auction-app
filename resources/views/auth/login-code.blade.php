<x-auth-layout :title="__('Enter your sign-in code')">
    <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-50">{{ __('Check your email') }}</h1>

    <div class="mt-2">
        <x-otp-form :action="route('login.code')" :resend-action="route('login.code.resend')"
                    :email="$email" :resend-in="$resendIn" :submit-label="__('Sign in')">
            <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
                <input type="checkbox" name="trust_device" value="1"
                       class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-900">
                <span>
                    {{ __('Trust this browser for :days days', ['days' => (int) config('auction.otp.trusted_device_days')]) }}
                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ __('We will not ask for a code here again until then. Only tick this on your own device.') }}</span>
                </span>
            </label>
        </x-otp-form>
    </div>

    <x-slot:below>
        <a href="{{ route('login') }}" class="font-semibold text-brand-700 hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">{{ __('Use a different account') }}</a>
    </x-slot:below>
</x-auth-layout>
