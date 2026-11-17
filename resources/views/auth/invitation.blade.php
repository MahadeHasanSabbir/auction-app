<x-auth-layout :title="__('Join a company')">
    @if ($invitation === null)
        <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-50">{{ __('This invitation is no longer valid') }}</h1>
        <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">{{ __('It may have expired, been withdrawn or already been used. Ask the company owner to send a new one.') }}</p>
        <x-button :href="route('login')" class="mt-6 w-full" variant="secondary">{{ __('Go to sign in') }}</x-button>
    @elseif (auth()->check())
        <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-50">{{ __('Sign out first') }}</h1>
        <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">{{ __('You are signed in as :email. Sign out and open the link again to create the team account.', ['email' => auth()->user()->email]) }}</p>
    @else
        <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-50">{{ __('Join :company', ['company' => $invitation->company->name]) }}</h1>
        <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ __('Create your account to work on this company\'s auctions.') }}</p>

        <form method="POST" action="{{ route('invitations.store', ['token' => $token]) }}" class="mt-6 space-y-5">
            @csrf

            <x-field :label="__('Email')" name="email_display" type="email" :value="$invitation->email" readonly disabled
                     :hint="__('The address the invitation was sent to.')" />
            <x-field :label="__('Your name')" name="name" required autocomplete="name" />
            <x-field :label="__('Phone (optional)')" name="phone" type="tel" autocomplete="tel" />

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field :label="__('Password')" name="password" type="password" required autocomplete="new-password" />
                <x-field :label="__('Repeat password')" name="password_confirmation" type="password" required autocomplete="new-password" />
            </div>

            @error('token')
                <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror

            <x-button class="w-full" size="lg">{{ __('Create account') }}</x-button>
        </form>
    @endif
</x-auth-layout>
