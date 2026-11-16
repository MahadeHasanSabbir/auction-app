@props([
    'action',
    'resendAction',
    'email',
    'resendIn' => 0,
    'submitLabel' => null,
])

{{--
    The form for an emailed one-time code: the code box, an optional slot for
    extra fields, the submit button and the "send a new code" button with its
    cooldown. Used by the sign-in, email confirmation and step-up pages.
--}}
<div>
    <p class="text-sm text-slate-600 dark:text-slate-400">
        {!! __('We sent a :length-digit code to :email. It works for :minutes minutes.', [
            'length' => (int) config('auction.otp.length'),
            'email' => '<strong class="font-semibold text-slate-900 dark:text-slate-100">'.e($email).'</strong>',
            'minutes' => (int) config('auction.otp.ttl_minutes'),
        ]) !!}
    </p>

    {{-- Local development only: the code, so the mail log need not be opened. --}}
    @if (app()->isLocal() && session('otp.debug_code'))
        <p class="mt-4 rounded-md border border-dashed border-amber-400 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-600 dark:bg-amber-950/40 dark:text-amber-200">
            {{ __('Local development: your code is') }}
            <strong class="tabular font-semibold tracking-widest">{{ session('otp.debug_code') }}</strong>
        </p>
    @endif

    <form method="POST" action="{{ $action }}" class="mt-6 space-y-5">
        @csrf

        <div>
            <label for="code" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Code') }}</label>
            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                   maxlength="{{ (int) config('auction.otp.length') }}" required autofocus
                   @error('code') aria-invalid="true" aria-describedby="code-error" @enderror
                   class="form-control tabular mt-1.5 py-3 text-center text-2xl font-semibold tracking-[0.5em] @error('code') border-red-500 dark:border-red-500 @enderror">
            @error('code')
                <p id="code-error" class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        {{ $slot }}

        <x-button class="w-full" size="lg">{{ $submitLabel ?? __('Confirm') }}</x-button>
    </form>

    <form method="POST" action="{{ $resendAction }}" class="mt-4 text-center text-sm" x-data="cooldown({{ (int) $resendIn }})">
        @csrf

        <span class="text-slate-600 dark:text-slate-400">{{ __('No email?') }}</span>
        <button type="submit" :disabled="left > 0"
                class="font-semibold text-brand-700 hover:text-brand-800 disabled:cursor-not-allowed disabled:text-slate-400 dark:text-brand-300 dark:hover:text-brand-200 dark:disabled:text-slate-500">
            <span x-show="left <= 0">{{ __('Send a new code') }}</span>
            <span x-show="left > 0" x-cloak>{{ __('Send a new code in') }} <span class="tabular" x-text="left > 90 ? Math.ceil(left / 60) + ' min' : left + 's'"></span></span>
        </button>
    </form>
</div>
