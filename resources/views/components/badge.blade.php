@props(['color' => 'slate', 'pulse' => false])

@php
    $colors = [
        'slate' => 'bg-slate-100 text-slate-700 ring-slate-500/20 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-400/20',
        'brand' => 'bg-brand-50 text-brand-700 ring-brand-600/20 dark:bg-brand-950 dark:text-brand-300 dark:ring-brand-400/30',
        'green' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-950 dark:text-emerald-300 dark:ring-emerald-400/30',
        'amber' => 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-950 dark:text-amber-300 dark:ring-amber-400/30',
        'red' => 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-950 dark:text-red-300 dark:ring-red-400/30',
        'blue' => 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-950 dark:text-sky-300 dark:ring-sky-400/30',
    ];
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', $colors[$color]]) }}>
    @if ($pulse)
        <span class="relative flex size-2">
            <span class="absolute inline-flex size-full animate-ping rounded-full bg-current opacity-60 motion-reduce:hidden"></span>
            <span class="relative inline-flex size-2 rounded-full bg-current"></span>
        </span>
    @endif
    {{ $slot }}
</span>
