<div>
    <x-account.shell :title="__('Invite friends')" :description="__('Share your link. When a friend signs up with it and completes their first order, you both get bonus credit.')">
        <x-card>
            @if ($forReferrer->isPositive() || $forReferred->isPositive())
                <p class="text-sm text-slate-700 dark:text-slate-300">
                    {{ __('You get :mine and your friend gets :theirs in bonus credit. Bonus credit pays for bids and purchases, and cannot be withdrawn.', ['mine' => $forReferrer->format(), 'theirs' => $forReferred->format()]) }}
                </p>
            @else
                <p class="text-sm text-slate-700 dark:text-slate-300">{{ __('Referral bonuses are paused at the moment, but your friends are still welcome.') }}</p>
            @endif

            @if ($link)
                <div class="mt-4" x-data="{ copied: false }">
                    <label for="referral-link" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Your link') }}</label>
                    <div class="mt-1.5 flex gap-2">
                        <input id="referral-link" type="text" readonly value="{{ $link }}" class="form-control" x-on:focus="$el.select()">
                        <x-button type="button" variant="secondary" x-on:click="navigator.clipboard.writeText(@js($link)); copied = true; setTimeout(() => copied = false, 2000)">
                            <span x-show="! copied">{{ __('Copy') }}</span>
                            <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                        </x-button>
                    </div>
                </div>
            @endif
        </x-card>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-card>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Friends who joined') }}</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900 dark:text-slate-50 tabular">{{ number_format($joined) }}</p>
            </x-card>
            <x-card>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Bonuses earned') }}</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900 dark:text-slate-50 tabular">{{ number_format($rewarded) }}</p>
            </x-card>
        </div>
    </x-account.shell>
</div>
