<div>
    <x-account.shell :title="__('Welcome back, :name', ['name' => Str::before($user->name, ' ')])" :description="__('Your wallet and the auctions you are bidding on.')">
        {{-- Wallet --}}
        <x-card>
            <div class="flex flex-wrap items-start justify-between gap-6">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Available balance') }}</p>
                    <p class="mt-1 font-display text-3xl font-bold text-slate-900 dark:text-slate-50">
                        @if ($wallet)
                            <x-money :amount="$wallet->available()" />
                        @else
                            –
                        @endif
                    </p>
                </div>

                @if ($wallet)
                    <dl class="grid grid-cols-2 gap-x-8 gap-y-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Total balance') }}</dt>
                            <dd class="mt-0.5 font-semibold text-slate-900 dark:text-slate-50"><x-money :amount="$wallet->balance" /></dd>
                        </div>
                        <div>
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Held for bids') }}</dt>
                            <dd class="mt-0.5 font-semibold text-slate-900 dark:text-slate-50"><x-money :amount="$wallet->held" /></dd>
                        </div>
                        <div>
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('You can bid up to') }}</dt>
                            <dd class="mt-0.5 font-semibold text-slate-900 dark:text-slate-50"><x-money :amount="$biddable" /></dd>
                        </div>
                    </dl>
                @endif
            </div>

            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4 dark:border-slate-800">
                <p class="text-sm text-slate-600 dark:text-slate-400">
                    {{ __('You can commit up to :percent% of your available balance to bids.', ['percent' => config('auction.bidding.biddable_share_bps') / 100]) }}
                </p>
                <div class="flex gap-2">
                    <x-button :href="route('wallet')" size="sm">{{ __('Add money') }}</x-button>
                    <x-button :href="route('wallet.withdraw')" variant="secondary" size="sm">{{ __('Withdraw') }}</x-button>
                </div>
            </div>
        </x-card>

        {{-- Numbers --}}
        <dl class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ([
                [__('Leading now'), $stats['leading']],
                [__('Watching'), $stats['watching']],
                [__('Auctions won'), $stats['won']],
                [__('Orders on the way'), $stats['openOrders']],
            ] as [$label, $value])
                <x-card>
                    <dt class="text-sm text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                    <dd class="tabular mt-1 font-display text-2xl font-bold text-slate-900 dark:text-slate-50">{{ $value }}</dd>
                </x-card>
            @endforeach
        </dl>

        {{-- Active bidding --}}
        <section aria-labelledby="bidding-on">
            <h2 id="bidding-on" class="font-sans text-lg font-semibold tracking-normal text-slate-900 dark:text-slate-50">{{ __('Auctions you are bidding on') }}</h2>

            @if ($bidding->isEmpty())
                <x-empty-state class="mt-3" :title="__('You have no bids on live auctions')" :description="__('Find something you like and place your first bid.')">
                    <x-button :href="route('auctions.index')">{{ __('Browse auctions') }}</x-button>
                </x-empty-state>
            @else
                <x-card :padding="false" class="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($bidding as $auction)
                        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4" wire:key="bidding-{{ $auction->id }}">
                            <div class="min-w-0">
                                <a href="{{ route('auctions.show', $auction) }}" class="font-semibold text-slate-900 hover:text-brand-700 dark:text-slate-50 dark:hover:text-brand-300">{{ $auction->title }}</a>
                                <p class="text-sm text-slate-500 dark:text-slate-400">{{ $auction->company->name }}</p>
                            </div>
                            <div class="flex items-center gap-4 text-sm">
                                @if ($auction->leader_id === $user->id)
                                    <x-badge color="green">{{ __('Leading') }}</x-badge>
                                @else
                                    <x-badge color="red">{{ __('Outbid') }}</x-badge>
                                @endif
                                <x-money :amount="$auction->current_price" class="font-semibold text-slate-900 dark:text-slate-50" />
                                @if ($auction->ends_at)
                                    <x-countdown :to="$auction->ends_at" class="w-20 text-right text-slate-600 dark:text-slate-400" />
                                @endif
                            </div>
                        </div>
                    @endforeach
                </x-card>
            @endif
        </section>
    </x-account.shell>
</div>
