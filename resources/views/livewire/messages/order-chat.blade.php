<div wire:init="markRead" @if ($poll) wire:poll.15s.visible @endif>
    <x-messages.thread :messages="$messages" :viewer-id="$viewerId"
                       :empty="auth()->user()->isBuyer() ? __('No messages yet. Ask the seller anything about this order.') : __('No messages yet. Write to the buyer about this order.')" />

    <form wire:submit="send" class="mt-4">
        <label for="chat-body-{{ $orderId }}" class="sr-only">{{ __('Your message') }}</label>
        <textarea id="chat-body-{{ $orderId }}" wire:model="body" rows="2" maxlength="{{ config('auction.messaging.message_max') }}"
                  placeholder="{{ __('Write a message…') }}" class="form-control h-20 resize-none"
                  x-on:keydown.ctrl.enter.prevent="$wire.send()"></textarea>
        @error('body') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

        <div class="mt-2 flex items-center justify-between gap-3">
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Keep payments on the platform; messages may be read by our team if there is a dispute.') }}</p>
            <x-button wire:loading.attr="disabled" wire:target="send">{{ __('Send') }}</x-button>
        </div>
    </form>
</div>
