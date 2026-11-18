<div wire:init="markRead">
    <x-account.shell :title="$ticket->subject" :description="__('Ticket #:id', ['id' => $ticket->id])">
        <x-card>
            <div class="mb-4 flex items-center justify-between gap-3">
                <x-badge :color="$isOpen ? 'green' : 'slate'">{{ $ticket->status->label() }}</x-badge>
                @if ($isOpen)
                    <x-button type="button" variant="secondary" size="sm" wire:click="close"
                              wire:confirm="{{ __('Close this ticket? You can reopen it by writing again.') }}">{{ __('Close ticket') }}</x-button>
                @endif
            </div>

            <x-messages.thread :messages="$messages" :viewer-id="$viewerId" :staff-label="__('Support')" :empty="__('No messages yet.')" />

            <form wire:submit="reply" class="mt-4">
                <label for="reply-body" class="sr-only">{{ __('Your reply') }}</label>
                <textarea id="reply-body" wire:model="body" rows="3" placeholder="{{ $isOpen ? __('Write a reply…') : __('Write to reopen this ticket…') }}" class="form-control h-20 resize-none"></textarea>
                @error('body') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                <div class="mt-2 flex justify-end">
                    <x-button wire:loading.attr="disabled" wire:target="reply">{{ __('Send') }}</x-button>
                </div>
            </form>
        </x-card>

        <p><a href="{{ route('support.index') }}" class="text-sm font-medium text-brand-700 hover:underline dark:text-brand-300">&larr; {{ __('All tickets') }}</a></p>
    </x-account.shell>
</div>
