@props(['icon' => 'gavel', 'title', 'description' => null])

<div {{ $attributes->class('flex flex-col items-center rounded-lg border border-dashed border-slate-300 px-6 py-12 text-center dark:border-slate-700') }}>
    <span class="flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
        <x-app-icon :name="$icon" class="size-6" />
    </span>
    <h3 class="mt-4 font-semibold text-slate-900 dark:text-slate-100">{{ $title }}</h3>
    @if ($description)
        <p class="mt-1 max-w-md text-sm text-slate-600 dark:text-slate-400">{{ $description }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-5 flex flex-wrap justify-center gap-3">{{ $slot }}</div>
    @endif
</div>
