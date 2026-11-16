@props(['align' => 'right', 'width' => 'w-56'])

{{-- A menu that opens under its trigger. Closes on outside click, Escape, or choosing an item. --}}
<div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
    <div @click="open = ! open" :aria-expanded="open">
        {{ $trigger }}
    </div>

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-75"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="absolute z-50 mt-2 {{ $width }} {{ $align === 'left' ? 'left-0 origin-top-left' : 'right-0 origin-top-right' }} overflow-hidden rounded-lg border border-slate-200 bg-white py-1 shadow-lg dark:border-slate-700 dark:bg-slate-900">
        {{ $content }}
    </div>
</div>
