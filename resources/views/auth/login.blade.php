<x-auth-layout :title="__('Sign in')">
    <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-50">{{ __('Sign in') }}</h1>
    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ __('Buyers, sellers and staff all sign in here.') }}</p>

    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-5">
        @csrf

        <x-field :label="__('Email')" name="email" type="email" required autofocus autocomplete="username" />

        <div>
            <div class="flex items-center justify-between">
                <label for="password" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Password') }}</label>
                <a href="{{ route('password.request') }}" class="text-sm font-medium text-brand-700 hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">{{ __('Forgot it?') }}</a>
            </div>
            <input id="password" name="password" type="password" required autocomplete="current-password"
                   @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                   class="form-control mt-1.5 @error('password') border-red-500 dark:border-red-500 @enderror">
            @error('password')
                <p id="password-error" class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
            <input type="checkbox" name="remember" value="1" @checked(old('remember'))
                   class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-900">
            {{ __('Keep me signed in') }}
        </label>

        <x-button class="w-full" size="lg">{{ __('Continue') }}</x-button>
    </form>

    <x-google-button />

    <x-slot:below>
        {{ __('New here?') }}
        <a href="{{ route('register') }}" class="font-semibold text-brand-700 hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">{{ __('Create an account') }}</a>
    </x-slot:below>
</x-auth-layout>
