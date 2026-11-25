<div wire:init="markRead" wire:poll.15s.visible>
    <x-messages.thread :messages="$messages" :viewer-id="$viewerId" :empty="__('No messages yet.')" />

    <form wire:submit="reply" class="mt-4">
        <label for="staff-reply-{{ $ticketId }}" class="sr-only">{{ __('Your reply') }}</label>
        <textarea id="staff-reply-{{ $ticketId }}" wire:model="body" rows="3" maxlength="{{ config('auction.messaging.ticket_max') }}"
                  placeholder="{{ $ticket->opened_by === null ? __('Write a reply; it is emailed to :email…', ['email' => $ticket->guest_email]) : __('Write a reply…') }}"
                  class="form-control h-24 resize-none"
                  x-on:keydown.ctrl.enter.prevent="$wire.reply()"></textarea>
        @error('body') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

        <div class="mt-2 flex items-center justify-between gap-3">
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Replies are signed “Support”, not with your name.') }}</p>
            <x-button wire:loading.attr="disabled" wire:target="reply">{{ __('Send reply') }}</x-button>
        </div>
    </form>
</div>
