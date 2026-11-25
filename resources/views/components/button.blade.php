@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'submit',
])

@php
    $classes = implode(' ', [
        'inline-flex items-center justify-center gap-2 rounded-md font-semibold transition-colors disabled:pointer-events-none disabled:opacity-50',
        [
            'sm' => 'px-3 py-1.5 text-sm',
            'md' => 'px-4 py-2.5 text-sm',
            'lg' => 'px-6 py-3 text-base',
        ][$size],
        [
            'primary' => 'bg-brand-600 text-white shadow-sm hover:bg-brand-700 dark:bg-brand-600 dark:hover:bg-brand-500',
            'secondary' => 'border border-slate-300 bg-white text-slate-800 shadow-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800',
            'danger' => 'bg-red-600 text-white shadow-sm hover:bg-red-700',
            'ghost' => 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800',
        ][$variant],
    ]);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
