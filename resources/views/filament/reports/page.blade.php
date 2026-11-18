<x-filament-panels::page>
    @if (filled($summary = $this->summary()))
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($summary as $label => $value)
                <div class="rounded-xl bg-white p-4 shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                    <div class="mt-1 text-xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $value }}</div>
                </div>
            @endforeach
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
