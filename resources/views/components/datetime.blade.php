@props(['value', 'format' => 'j M Y, g:i A'])

{{-- A stored UTC time, shown in the viewer's timezone (or the marketplace default). --}}
@php
    $timezone = auth()->user()?->displayTimezone() ?? config('auction.display_timezone');
    $local = $value->setTimezone($timezone);
@endphp

<time datetime="{{ $value->toIso8601String() }}" title="{{ $local->format('l, j F Y, g:i A T') }}" {{ $attributes }}>{{ $local->format($format) }}</time>
