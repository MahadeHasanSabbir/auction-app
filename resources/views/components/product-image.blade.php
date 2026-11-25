@props(['image' => null, 'seed' => 0, 'label' => null, 'alt' => '', 'thumbnail' => true])

{{--
    A product picture. Without one, a lightly tinted panel with the category name
    stands in, so cards keep their shape; the tint is picked from the seed and
    stays the same for the same item.
--}}
@php
    $tints = [
        'bg-slate-100 text-slate-500',
        'bg-brand-50 text-brand-600',
        'bg-sky-50 text-sky-600',
        'bg-amber-50 text-amber-700',
        'bg-emerald-50 text-emerald-700',
        'bg-rose-50 text-rose-600',
    ];
@endphp

@if ($image)
    <img src="{{ Storage::disk('public')->url($thumbnail ? ($image->thumbnail_path ?? $image->path) : $image->path) }}"
         alt="{{ $image->alt ?? $alt }}" loading="lazy" decoding="async"
         {{ $attributes->class('h-full w-full object-cover') }}>
@else
    <div {{ $attributes->class(['flex h-full w-full flex-col items-center justify-center gap-2 dark:bg-slate-800 dark:text-slate-400', $tints[$seed % count($tints)]]) }}>
        <x-app-icon name="photo" class="size-8 opacity-60" />
        @if ($label)
            <span class="px-3 text-center text-xs font-semibold uppercase tracking-wider opacity-80">{{ $label }}</span>
        @endif
    </div>
@endif
