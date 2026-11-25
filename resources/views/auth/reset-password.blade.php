<x-auth-layout :title="__('Choose a new password')">
    <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-50">{{ __('Choose a new password') }}</h1>

    <div class="mt-2">
        <x-otp-form :action="route('password.store')" :resend-action="route('password.resend')"
                    :email="$email" :resend-in="$resendIn" :submit-label="__('Save new password')">
            <x-field :label="__('New password')" name="password" type="password" required autocomplete="new-password" />
            <x-field :label="__('Repeat new password')" name="password_confirmation" type="password" required autocomplete="new-password" />
        </x-otp-form>
    </div>

    <x-slot:below>
        <a href="{{ route('password.request') }}" class="font-semibold text-brand-700 hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">{{ __('Use a different address') }}</a>
    </x-slot:below>
</x-auth-layout>
