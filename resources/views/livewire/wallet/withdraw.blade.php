<div>
    <x-account.shell :title="__('Withdraw')" :description="__('Staff send the money by hand, usually within two working days. Until then it stays on hold in your wallet.')">
        <x-card class="max-w-2xl">
            <p class="text-sm text-slate-600 dark:text-slate-400">
                {{ __('Available to withdraw:') }}
                <x-money :amount="$wallet->available()" class="font-semibold text-slate-900 dark:text-slate-50" />
            </p>

            <form wire:submit="submit" class="mt-5 space-y-5">
                <x-field :label="__('Amount (:currency)', ['currency' => $wallet->currency])" name="amount" inputmode="decimal" autocomplete="off"
                         wire:model.live.debounce.400ms="amount" :hint="__('At least :min.', ['min' => $min->format()])" />

                <fieldset>
                    <legend class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Send it to') }}</legend>
                    <div class="mt-1.5 grid grid-cols-2 gap-2 sm:grid-cols-4">
                        @foreach ($methods as $option)
                            <label class="flex cursor-pointer items-center gap-2 rounded-md border border-slate-200 px-3 py-2.5 text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50 dark:border-slate-700 dark:has-[:checked]:bg-brand-950" wire:key="method-{{ $option->value }}">
                                <input type="radio" value="{{ $option->value }}" wire:model.live="method" class="text-brand-600">
                                <span class="text-slate-800 dark:text-slate-200">{{ $option->label() }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('method')
                        <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </fieldset>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field :label="__('Account holder')" name="account_name" wire:model="account_name" autocomplete="name" />
                    <x-field :label="$isBank ? __('Account number') : __('Mobile number')" name="account_number" wire:model="account_number"
                             inputmode="numeric" autocomplete="off" :hint="$isBank ? null : __('For example 01712345678.')" />

                    @if ($isBank)
                        <x-field :label="__('Bank')" name="bank_name" wire:model="bank_name" />
                        <x-field :label="__('Branch')" name="branch" wire:model="branch" />
                        <x-field :label="__('Routing number (optional)')" name="routing_number" wire:model="routing_number" inputmode="numeric" />
                    @endif
                </div>

                @if ($quote)
                    <dl class="space-y-1 rounded-md bg-slate-50 px-3 py-2.5 text-sm dark:bg-slate-800/50">
                        <div class="flex justify-between"><dt class="text-slate-600 dark:text-slate-400">{{ __('Transfer charge') }}</dt><dd><x-money :amount="$quote['gateway']->amount" /></dd></div>
                        <div class="flex justify-between"><dt class="text-slate-600 dark:text-slate-400">{{ __('Service charge') }}</dt><dd><x-money :amount="$quote['service']->amount" /></dd></div>
                        <div class="flex justify-between font-semibold text-slate-900 dark:text-slate-50"><dt>{{ __('You receive') }}</dt><dd><x-money :amount="$quote['net']" /></dd></div>
                    </dl>
                @endif

                <div class="flex flex-wrap items-center gap-3">
                    <x-button wire:loading.attr="disabled" wire:target="submit">{{ __('Request withdrawal') }}</x-button>
                    <x-button :href="route('wallet')" variant="ghost">{{ __('Back to wallet') }}</x-button>
                </div>
            </form>
        </x-card>
    </x-account.shell>
</div>
