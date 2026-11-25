@props(['to', 'doneLabel' => null])

{{--
    A live countdown to $to. The server sends both the target and its own
    clock, so the visitor's device time does not matter. Without JavaScript
    the relative time ("in 3 hours") printed by the server stays visible.
--}}
<span x-data="countdown({{ $to->getTimestampMs() }}, {{ now()->getTimestampMs() }})"
      {{ $attributes->class('tabular whitespace-nowrap') }}
      :class="urgent && 'text-red-600 dark:text-red-400'">
    <span x-show="! done" x-text="label">{{ $to->diffForHumans(['parts' => 2, 'short' => true]) }}</span>
    <span x-show="done" x-cloak>{{ $doneLabel ?? __('Ended') }}</span>
</span>
