@props(['messages', 'viewerId', 'staffLabel' => null, 'empty'])

{{-- A conversation as bubbles: the viewer's on the right. Messages by platform staff can show a team label instead of a name. --}}
<div x-data="{ down() { this.$nextTick(() => { this.$refs.list.scrollTop = this.$refs.list.scrollHeight }) } }"
     x-init="down()" x-on:chat-updated.window="down()">
    <ol x-ref="list" class="max-h-96 space-y-3 overflow-y-auto pr-1" aria-live="polite">
        @forelse ($messages as $message)
            @php($mine = $message->user_id === $viewerId)
            <li wire:key="message-{{ $message->id }}" @class(['flex flex-col', 'items-end' => $mine, 'items-start' => ! $mine])>
                <div @class([
                    'max-w-[85%] rounded-lg px-3.5 py-2 text-sm',
                    'bg-brand-600 text-white' => $mine,
                    'bg-slate-100 text-slate-900 dark:bg-slate-800 dark:text-slate-100' => ! $mine,
                ])>
                    <p class="whitespace-pre-line break-words">{{ $message->body }}</p>
                </div>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    @if ($mine)
                        {{ __('You') }}
                    @elseif ($message->author?->isPlatform() && $staffLabel)
                        {{ $staffLabel }}
                    @else
                        {{ $message->author?->name ?? __('Guest') }}
                    @endif
                    · <x-datetime :value="$message->created_at" format="j M, H:i" />
                </p>
            </li>
        @empty
            <li class="py-6 text-center text-sm text-slate-500 dark:text-slate-400">{{ $empty }}</li>
        @endforelse
    </ol>
</div>
