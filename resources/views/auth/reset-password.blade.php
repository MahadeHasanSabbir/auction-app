<x-auth-layout :title="__('Choose a new password')">
    <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-50">{{ __('Choose a new password') }}</h1>

    <form method="POST" action="{{ route('password.store') }}" class="mt-6 space-y-5">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-field :label="__('Email')" name="email" type="email" :value="$request->email" required autocomplete="username" />
        <x-field :label="__('New password')" name="password" type="password" required autofocus autocomplete="new-password" />
        <x-field :label="__('Repeat new password')" name="password_confirmation" type="password" required autocomplete="new-password" />

        <x-button class="w-full" size="lg">{{ __('Save new password') }}</x-button>
    </form>
</x-auth-layout>
