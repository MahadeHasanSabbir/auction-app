<div>
    <x-account.shell :title="__('Support')" :description="__('Ask our team for help with an order, a payment or your account.')">
        <div class="flex justify-end">
            @if (! $opening)
                <x-button type="button" wire:click="$set('opening', true)">{{ __('New ticket') }}</x-button>
            @endif
        </div>

        @if ($opening)
            <x-card>
                <form wire:submit="open" class="space-y-4">
                    <h2 class="font-semibold text-slate-900 dark:text-slate-50">{{ __('How can we help?') }}</h2>
                    <x-field :label="__('Subject')" name="subject" wire:model="subject" maxlength="120" />
                    <div>
                        <label for="body" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Message') }}</label>
                        <textarea id="body" wire:model="body" rows="5" class="form-control mt-1.5 h-24 resize-none"></textarea>
                        @error('body') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex gap-2">
                        <x-button>{{ __('Send to support') }}</x-button>
                        @if ($tickets->isNotEmpty())
                            <x-button type="button" variant="ghost" wire:click="$set('opening', false)">{{ __('Cancel') }}</x-button>
                        @endif
                    </div>
                </form>
            </x-card>
        @endif

        @if ($tickets->isNotEmpty())
            <x-card :padding="false" class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($tickets as $ticket)
                    <a href="{{ route('support.show', $ticket) }}" wire:key="ticket-{{ $ticket->id }}"
                       class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 hover:bg-slate-50 dark:hover:bg-slate-800/50">
                        <span class="min-w-0">
                            <span class="block truncate font-medium text-slate-900 dark:text-slate-100">{{ $ticket->subject }}</span>
                            <span class="mt-0.5 block text-xs text-slate-500">#{{ $ticket->id }} · {{ $ticket->last_message_at?->diffForHumans() }}</span>
                        </span>
                        <x-badge :color="$ticket->status->value === 'open' ? 'green' : 'slate'">{{ $ticket->status->label() }}</x-badge>
                    </a>
                @endforeach
            </x-card>
        @elseif (! $opening)
            <x-empty-state icon="chat" :title="__('No tickets yet')" :description="__('When you write to support, the conversation appears here.')" />
        @endif
    </x-account.shell>
</div>
