<div class="mx-auto max-w-2xl px-4 py-10 sm:px-6">
    <h1 class="text-3xl font-semibold text-slate-900 dark:text-slate-50">{{ __('Contact us') }}</h1>
    <p class="mt-2 text-slate-600 dark:text-slate-400">{{ __('Questions about buying, selling or your account? Write to us and our team will answer.') }}</p>

    @if ($sentTicket !== null)
        <x-card class="mt-8">
            <h2 class="font-semibold text-slate-900 dark:text-slate-50">{{ __('Thank you, we got your message.') }}</h2>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">
                @if ($signedIn && $sentTicket > 0)
                    {{ __('You can follow the answer in your support tickets.') }}
                @else
                    {{ __('Our answer will be sent to the email address you gave.') }}
                @endif
            </p>
            <div class="mt-5 flex gap-3">
                @if ($signedIn && $sentTicket > 0)
                    <x-button :href="route('support.show', $sentTicket)">{{ __('Open ticket') }}</x-button>
                @endif
                <x-button :href="route('home')" variant="secondary">{{ __('Back to home') }}</x-button>
            </div>
        </x-card>
    @else
        <x-card class="mt-8">
            <form wire:submit="send" class="space-y-4">
                @unless ($signedIn)
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-field :label="__('Your name')" name="name" wire:model="name" autocomplete="name" />
                        <x-field :label="__('Email address')" name="email" type="email" wire:model="email" autocomplete="email" />
                    </div>
                @endunless
                <x-field :label="__('Subject')" name="subject" wire:model="subject" maxlength="120" />
                <div>
                    <label for="body" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Message') }}</label>
                    <textarea id="body" wire:model="body" rows="6" class="form-control mt-1.5 h-24 resize-none"></textarea>
                    @error('body') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                {{-- Honeypot: invisible to people. --}}
                <div class="absolute -left-[9999px]" aria-hidden="true">
                    <label>{{ __('Leave this empty') }} <input type="text" wire:model="website" tabindex="-1" autocomplete="off"></label>
                </div>

                <x-button>{{ __('Send message') }}</x-button>
            </form>
        </x-card>
    @endif
</div>
