<x-auth-layout :title="__('Reset your password')">
    <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-50">{{ __('Reset your password') }}</h1>
    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ __('Enter your email address and we will send you a code to choose a new password.') }}</p>

    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-5">
        @csrf

        <x-field :label="__('Email')" name="email" type="email" required autofocus autocomplete="username" />

        <x-button class="w-full" size="lg">{{ __('Email me a code') }}</x-button>
    </form>

    <x-slot:below>
        <a href="{{ route('login') }}" class="font-semibold text-brand-700 hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">{{ __('Back to sign in') }}</a>
    </x-slot:below>
</x-auth-layout>
