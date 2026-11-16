@props(['padding' => true])

<div {{ $attributes->class([
    'rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900',
    'p-5 sm:p-6' => $padding,
]) }}>
    {{ $slot }}
</div>
