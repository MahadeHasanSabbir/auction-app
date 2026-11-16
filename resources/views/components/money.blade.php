@props(['amount'])

{{-- An App\Support\Money, formatted for the current locale with aligned digits. --}}
<span {{ $attributes->class('tabular whitespace-nowrap') }}>{{ $amount->format() }}</span>
