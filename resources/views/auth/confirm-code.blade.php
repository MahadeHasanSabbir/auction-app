<x-auth-layout :title="__('Confirm it is you')">
    <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-50">{{ __('Confirm it is you') }}</h1>
    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ __('Sign-in and security settings open after a fresh code.') }}</p>

    @if ($sendError)
        <p class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-950/50 dark:text-red-200" role="alert">{{ $sendError }}</p>
    @endif

    <div class="mt-4">
        <x-otp-form :action="route('otp.confirm')" :resend-action="route('otp.confirm.resend')"
                    :email="$email" :resend-in="$resendIn" />
    </div>

    <x-slot:below>
        <a href="{{ route('profile.edit') }}" class="font-semibold text-brand-700 hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">{{ __('Back to your profile') }}</a>
    </x-slot:below>
</x-auth-layout>
