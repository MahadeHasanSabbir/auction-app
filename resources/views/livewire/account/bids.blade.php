@php
    use App\Enums\AuctionStatus;
    use App\Support\Money;
@endphp

<div>
    <x-account.shell :title="__('My bids')" :description="__('Auctions you bid on, the ones you won, and your watchlist.')">
        <nav aria-label="{{ __('Filter') }}" class="flex gap-1 overflow-x-auto overflow-y-hidden border-b border-slate-200 dark:border-slate-800">
            @foreach ($tabs as $key => $label)
                <button type="button" wire:click="$set('tab', '{{ $key }}')" @if ($tab === $key) aria-current="page" @endif
                        @class([
                            '-mb-px shrink-0 border-b-2 px-3 py-2 text-sm font-medium',
                            'border-brand-600 text-brand-700 dark:border-brand-400 dark:text-brand-300' => $tab === $key,
                            'border-transparent text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100' => $tab !== $key,
                        ])>
                    {{ $label }}
                </button>
            @endforeach
        </nav>

        @if ($auctions->isEmpty())
            <x-empty-state
                :title="match ($tab) {
                    'won' => __('No auctions won yet'),
                    'lost' => __('Nothing here'),
                    'watching' => __('Your watchlist is empty'),
                    default => __('You have no bids on live auctions'),
                }"
                :description="$tab === 'watching' ? __('Tap “Add to watchlist” on an auction to follow it here.') : __('Find something you like and place a bid.')">
                <x-button :href="route('auctions.index')">{{ __('Browse auctions') }}</x-button>
            </x-empty-state>
        @else
            <x-card :padding="false" class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($auctions as $auction)
                    @php
                        $topBid = $auction->my_top_bid !== null ? Money::minor((int) $auction->my_top_bid) : null;
                        $maximum = $maximums[$auction->id] ?? null;
                    @endphp
                    <div class="flex flex-wrap items-center gap-4 px-5 py-4" wire:key="bid-row-{{ $auction->id }}">
                        <a href="{{ $auction->url() }}" class="size-14 shrink-0 overflow-hidden rounded-md bg-slate-100 dark:bg-slate-800">
                            <x-product-image :image="$auction->product?->coverImage" :seed="$auction->id" :alt="$auction->title" />
                        </a>

                        <div class="min-w-0 flex-1">
                            <a href="{{ $auction->url() }}" class="font-semibold text-slate-900 hover:text-brand-700 dark:text-slate-50 dark:hover:text-brand-300">{{ $auction->title }}</a>
                            <p class="text-sm text-slate-500 dark:text-slate-400">
                                {{ $auction->company->name }}
                                @if ($topBid)
                                    · {{ __('Your highest bid :amount', ['amount' => $topBid->format()]) }}
                                @endif
                                @if ($maximum)
                                    · {{ __('your maximum :amount', ['amount' => $maximum->format()]) }}
                                @endif
                            </p>
                        </div>

                        <div class="flex items-center gap-4 text-sm">
                            @if ($auction->status === AuctionStatus::Live)
                                @if ($topBid)
                                    @if ($auction->leader_id === $user->id)
                                        <x-badge color="green">{{ __('Leading') }}</x-badge>
                                    @else
                                        <x-badge color="red">{{ __('Outbid') }}</x-badge>
                                    @endif
                                @endif
                            @elseif ($auction->status === AuctionStatus::Sold && $auction->winner_id === $user->id)
                                <x-badge color="green">{{ __('Won') }}</x-badge>
                                @if ($auction->relationLoaded('order') && $auction->order)
                                    <a href="{{ route('orders.show', $auction->order) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-300">{{ __('Order') }}</a>
                                @endif
                            @else
                                <x-auction.status :auction="$auction" />
                            @endif

                            <div class="text-right">
                                <x-money :amount="$auction->final_price ?? $auction->current_price" class="font-semibold text-slate-900 dark:text-slate-50" />
                                @if ($auction->status === AuctionStatus::Live && $auction->ends_at)
                                    <x-countdown :to="$auction->ends_at" class="block text-xs text-slate-500 dark:text-slate-400" />
                                @elseif ($auction->closed_at)
                                    <x-datetime :value="$auction->closed_at" format="j M Y" class="block text-xs text-slate-500 dark:text-slate-400" />
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </x-card>

            {{ $auctions->links() }}
        @endif
    </x-account.shell>
</div>
