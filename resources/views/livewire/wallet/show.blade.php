<div>
    <x-account.shell :title="__('Wallet')" :description="__('Add money, withdraw it and see every movement.')">
        @if ($notice)
            <div role="status" @class([
                'rounded-lg border px-4 py-3 text-sm',
                'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' => $notice['type'] === 'success',
                'border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200' => $notice['type'] === 'error',
                'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300' => $notice['type'] === 'info',
            ])>{{ $notice['message'] }}</div>
        @endif

        {{-- Balances --}}
        <x-card>
            <dl class="grid grid-cols-2 gap-6 sm:grid-cols-3">
                <div class="col-span-2 sm:col-span-3">
                    <dt class="text-sm text-slate-500 dark:text-slate-400">{{ __('Available') }}</dt>
                    <dd class="mt-1 font-display text-3xl font-bold text-slate-900 dark:text-slate-50"><x-money :amount="$wallet->available()" /></dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500 dark:text-slate-400">{{ __('Total balance') }}</dt>
                    <dd class="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-50"><x-money :amount="$wallet->balance" /></dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500 dark:text-slate-400">{{ __('On hold') }}</dt>
                    <dd class="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-50"><x-money :amount="$wallet->held" /></dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500 dark:text-slate-400">{{ __('You can bid up to') }}</dt>
                    <dd class="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-50"><x-money :amount="$biddable" /></dd>
                </div>
            </dl>
            <p class="mt-5 border-t border-slate-100 pt-4 text-sm text-slate-600 dark:border-slate-800 dark:text-slate-400">
                {{ __('Money is on hold while you lead an auction or wait for a withdrawal. You can commit up to :percent% of your available balance to bids.', ['percent' => config('auction.bidding.biddable_share_bps') / 100]) }}
            </p>
        </x-card>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Add money --}}
            <x-card>
                <h2 class="font-sans text-lg font-semibold tracking-normal text-slate-900 dark:text-slate-50">{{ __('Add money') }}</h2>

                @if ($gateways === [])
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">{{ __('Payments are not available at the moment.') }}</p>
                @else
                    <form wire:submit="startDeposit" class="mt-4 space-y-4">
                        <x-field :label="__('Amount (:currency)', ['currency' => $wallet->currency])" name="amount" inputmode="decimal" autocomplete="off"
                                 wire:model.live.debounce.400ms="amount"
                                 :hint="__('Between :min and :max.', ['min' => $limits['min']->format(), 'max' => $limits['max']->format()])" />

                        <fieldset>
                            <legend class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Pay with') }}</legend>
                            <div class="mt-1.5 space-y-2">
                                @foreach ($gateways as $name => $driver)
                                    <label class="flex cursor-pointer items-center gap-3 rounded-md border border-slate-200 px-3 py-2.5 text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50 dark:border-slate-700 dark:has-[:checked]:bg-brand-950" wire:key="gateway-{{ $name }}">
                                        <input type="radio" value="{{ $name }}" wire:model.live="gateway" class="text-brand-600">
                                        <span class="text-slate-800 dark:text-slate-200">{{ $driver->label() }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('gateway')
                                <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </fieldset>

                        @if ($quote)
                            <dl class="space-y-1 rounded-md bg-slate-50 px-3 py-2.5 text-sm dark:bg-slate-800/50">
                                <div class="flex justify-between"><dt class="text-slate-600 dark:text-slate-400">{{ __('Charges') }}</dt><dd><x-money :amount="$quote['charges']" /></dd></div>
                                <div class="flex justify-between font-semibold text-slate-900 dark:text-slate-50"><dt>{{ __('Added to your wallet') }}</dt><dd><x-money :amount="$quote['net']" /></dd></div>
                            </dl>
                        @endif

                        <x-button class="w-full" wire:loading.attr="disabled" wire:target="startDeposit">{{ __('Continue to payment') }}</x-button>
                    </form>
                @endif
            </x-card>

            {{-- Withdrawals --}}
            <x-card>
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-sans text-lg font-semibold tracking-normal text-slate-900 dark:text-slate-50">{{ __('Withdrawals') }}</h2>
                    <x-button :href="route('wallet.withdraw')" variant="secondary" size="sm">{{ __('Withdraw') }}</x-button>
                </div>

                @forelse ($withdrawals as $withdrawal)
                    <div class="mt-4 flex flex-wrap items-start justify-between gap-3 border-t border-slate-100 pt-4 text-sm first-of-type:border-0 dark:border-slate-800" wire:key="withdrawal-{{ $withdrawal->id }}">
                        <div class="min-w-0">
                            <p class="font-semibold text-slate-900 dark:text-slate-50"><x-money :amount="$withdrawal->amount" /></p>
                            <p class="text-slate-500 dark:text-slate-400">{{ $withdrawal->destinationSummary() }} · <x-datetime :value="$withdrawal->created_at" format="j M Y" /></p>
                            @if ($withdrawal->rejection_reason)
                                <p class="mt-1 text-red-700 dark:text-red-300">{{ $withdrawal->rejection_reason }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <x-badge :color="match ($withdrawal->status) {
                                \App\Enums\WithdrawalStatus::Paid => 'green',
                                \App\Enums\WithdrawalStatus::Rejected => 'red',
                                \App\Enums\WithdrawalStatus::Cancelled => 'slate',
                                default => 'amber',
                            }">{{ $withdrawal->status->label() }}</x-badge>
                            @if ($withdrawal->status === \App\Enums\WithdrawalStatus::Pending)
                                <x-button type="button" variant="ghost" size="sm"
                                          wire:click="cancelWithdrawal({{ $withdrawal->id }})"
                                          wire:confirm="{{ __('Cancel this withdrawal?') }}">{{ __('Cancel') }}</x-button>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">{{ __('Withdraw your available balance to a bank account or bKash, Nagad or Rocket. Staff send it within two working days.') }}</p>
                @endforelse
            </x-card>
        </div>

        {{-- Recent payments --}}
        @if ($deposits->isNotEmpty())
            <x-card :padding="false">
                <h2 class="px-5 pt-5 font-sans text-lg font-semibold tracking-normal text-slate-900 sm:px-6 dark:text-slate-50">{{ __('Recent payments') }}</h2>
                <ul class="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($deposits as $payment)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm sm:px-6" wire:key="deposit-{{ $payment->id }}">
                            <span class="text-slate-600 dark:text-slate-400"><x-datetime :value="$payment->created_at" /></span>
                            <span class="flex items-center gap-3">
                                <x-money :amount="$payment->amount" class="font-semibold text-slate-900 dark:text-slate-50" />
                                <x-badge :color="match ($payment->status) {
                                    \App\Enums\DepositStatus::Succeeded => 'green',
                                    \App\Enums\DepositStatus::Pending => 'amber',
                                    default => 'red',
                                }">{{ $payment->status->label() }}</x-badge>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        @endif

        {{-- Statement --}}
        <section aria-labelledby="statement">
            <h2 id="statement" class="font-sans text-lg font-semibold tracking-normal text-slate-900 dark:text-slate-50">{{ __('Statement') }}</h2>

            @if ($entries->isEmpty())
                <x-empty-state icon="wallet" class="mt-3" :title="__('No movements yet')" :description="__('Money you add, spend or withdraw is listed here.')" />
            @else
                <x-card :padding="false" class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-slate-200 text-slate-500 dark:border-slate-800 dark:text-slate-400">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-medium">{{ __('Date') }}</th>
                                <th scope="col" class="px-5 py-3 font-medium">{{ __('Description') }}</th>
                                <th scope="col" class="px-5 py-3 text-right font-medium">{{ __('Amount') }}</th>
                                <th scope="col" class="px-5 py-3 text-right font-medium">{{ __('Balance') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($entries as $entry)
                                <tr wire:key="entry-{{ $entry->id }}">
                                    <td class="whitespace-nowrap px-5 py-3 text-slate-600 dark:text-slate-400"><x-datetime :value="$entry->transaction->created_at" format="j M Y" /></td>
                                    <td class="px-5 py-3">
                                        <p class="font-medium text-slate-900 dark:text-slate-50">{{ $entry->transaction->type->label() }}</p>
                                        @if ($entry->transaction->description)
                                            <p class="text-slate-500 dark:text-slate-400">{{ $entry->transaction->description }}</p>
                                        @endif
                                    </td>
                                    <td @class(['px-5 py-3 text-right font-semibold', 'text-emerald-700 dark:text-emerald-400' => $entry->amount->isPositive(), 'text-slate-900 dark:text-slate-50' => ! $entry->amount->isPositive()])>
                                        {{ $entry->amount->isPositive() ? '+' : '' }}<x-money :amount="$entry->amount" />
                                    </td>
                                    <td class="px-5 py-3 text-right text-slate-600 dark:text-slate-400"><x-money :amount="$entry->balance_after" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-card>

                <div class="mt-4">{{ $entries->links() }}</div>
            @endif
        </section>
    </x-account.shell>
</div>
