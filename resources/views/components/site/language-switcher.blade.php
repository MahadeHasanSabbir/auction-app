{{-- English / বাংলা: a plain form, so it works without JavaScript and keeps the current page. --}}
@php($current = app()->getLocale())
<form method="POST" action="{{ route('locale') }}" {{ $attributes->class('flex items-center') }}>
    @csrf
    @foreach (config('auction.locales') as $code => $name)
        @continue($code === $current)
        <button type="submit" name="locale" value="{{ $code }}" lang="{{ $code }}"
                class="rounded-md px-2 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100"
                title="{{ __('Change language') }}">
            <span class="sr-only">{{ __('Change language') }}:</span> {{ $name }}
        </button>
    @endforeach
</form>
