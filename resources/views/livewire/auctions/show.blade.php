@php
    use App\Enums\AuctionStatus;

    $product = $auction->product;
    $images = $product?->images ?? collect();
    $category = $product?->category;
    $live = $auction->status === AuctionStatus::Live;
    $scheduled = $auction->status === AuctionStatus::Scheduled;
    $sold = $auction->status === AuctionStatus::Sold;
    $bids = $snapshot['bids'];
    $leading = $viewerId !== null && $auction->leader_id === $viewerId;
    $outbid = $viewer !== null && $viewer['hasBid'] && ! $leading;
@endphp

{{--
    The live bidding room (auctionRoom in resources/js/live.js): price, time
    left, bids and viewers follow the auction's channel. Without a websocket
    connection it refreshes itself every few seconds instead.
--}}
<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8"
     x-data="auctionRoom(@js([
         'id' => $auction->id,
         'me' => $me,
         'hasBid' => $viewer['hasBid'] ?? false,
         'pollSeconds' => (int) config('auction.realtime.fallback_poll_seconds'),
         'timezone' => auth()->user()?->displayTimezone() ?? config('auction.display_timezone'),
     ]))">
    {{-- The server's snapshot, applied again after every render: the new key makes Alpine run it. --}}
    <div hidden wire:key="snapshot-{{ $snapshot['server_time'] }}" x-init="apply(@js($snapshot))"></div>

    <nav aria-label="{{ __('Breadcrumb') }}" class="flex flex-wrap items-center gap-1.5 text-sm text-slate-500 dark:text-slate-400">
        <a href="{{ route('auctions.index') }}" class="hover:text-brand-700 dark:hover:text-brand-300">{{ __('Auctions') }}</a>
        @if ($category?->parent)
            <x-app-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('auctions.index', ['category' => $category->parent->slug]) }}" class="hover:text-brand-700 dark:hover:text-brand-300">{{ $category->parent->name }}</a>
        @endif
        @if ($category)
            <x-app-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('auctions.index', ['category' => $category->slug]) }}" class="hover:text-brand-700 dark:hover:text-brand-300">{{ $category->name }}</a>
        @endif
    </nav>

    <div class="mt-4 grid gap-8 lg:grid-cols-[1fr_24rem]">
        <div class="min-w-0 space-y-8">
            {{-- Gallery --}}
            <div x-data="{ current: 0 }">
                <div class="aspect-[4/3] overflow-hidden rounded-lg border border-slate-200 bg-slate-100 dark:border-slate-800 dark:bg-slate-800">
                    @forelse ($images as $index => $image)
                        <x-product-image :image="$image" :thumbnail="false" :alt="$auction->title" x-show="current === {{ $index }}" @if ($index > 0) x-cloak @endif />
                    @empty
                        <x-product-image :seed="$auction->id" :label="$category?->name" />
                    @endforelse
                </div>

                @if ($images->count() > 1)
                    <div class="mt-3 flex gap-2 overflow-x-auto pb-1">
                        @foreach ($images as $index => $image)
                            <button type="button" @click="current = {{ $index }}"
                                    class="size-16 shrink-0 overflow-hidden rounded-md border-2"
                                    :class="current === {{ $index }} ? 'border-brand-600' : 'border-transparent opacity-70 hover:opacity-100'"
                                    aria-label="{{ __('Show picture :number', ['number' => $index + 1]) }}">
                                <x-product-image :image="$image" />
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-auction.status :auction="$auction" />
                    @if ($product?->condition)
                        <x-badge>{{ $product->condition->label() }}</x-badge>
                    @endif
                    @if ($open)
                        <span x-show="viewers" x-cloak class="inline-flex items-center gap-1 text-sm text-slate-500 dark:text-slate-400">
                            <x-app-icon name="eye" class="size-4" /> <span x-text="viewersLabel"></span>
                        </span>
                    @endif
                </div>
                <h1 class="mt-3 text-2xl font-semibold text-slate-900 dark:text-slate-50 sm:text-3xl">{{ $auction->title }}</h1>
            </div>

            <section aria-labelledby="description">
                <h2 id="description" class="font-sans text-lg font-semibold tracking-normal text-slate-900 dark:text-slate-50">{{ __('Description') }}</h2>
                <div class="mt-3 max-w-prose whitespace-pre-line leading-relaxed text-slate-700 dark:text-slate-300">{{ $product?->description ?: __('The seller has not added a description.') }}</div>
            </section>

            {{-- Bid history --}}
            <section aria-labelledby="bid-history">
                <h2 id="bid-history" class="font-sans text-lg font-semibold tracking-normal text-slate-900 dark:text-slate-50">
                    {{ __('Bid history') }}
                    <span class="font-normal text-slate-500 dark:text-slate-400">(<span x-text="bidCount">{{ $auction->bid_count }}</span>)</span>
                </h2>

                <p class="mt-3 text-sm text-slate-600 dark:text-slate-400" x-show="feed.length === 0" @if ($bids !== []) style="display: none" @endif>
                    {{ $scheduled ? __('Bidding has not started yet.') : __('No bids were placed.') }}
                </p>

                <div x-show="feed.length > 0" @if ($bids === []) style="display: none" @endif>
                    <x-card :padding="false" class="mt-3 overflow-hidden">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                                <tr>
                                    <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('Bidder') }}</th>
                                    <th scope="col" class="px-4 py-2.5 text-right font-semibold">{{ __('Amount') }}</th>
                                    <th scope="col" class="px-4 py-2.5 text-right font-semibold">{{ __('When') }}</th>
                                </tr>
                            </thead>
                            {{-- Other bidders only ever see a masked name. The server renders the first paint; Alpine takes over from the snapshot. --}}
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800" x-show="false">
                                @foreach ($bids as $bid)
                                    <tr @class(['bg-brand-50/60 dark:bg-brand-950/40' => $loop->first])>
                                        <td class="px-4 py-2.5 text-slate-800 dark:text-slate-200">
                                            {{ $bid['ref'] === $me ? __('You') : $bid['bidder'] }}
                                            @if ($loop->first)
                                                <x-badge color="brand" class="ml-2">{{ $live ? __('Leading') : __('Highest') }}</x-badge>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 text-right font-semibold text-slate-900 dark:text-slate-50"><span class="tabular whitespace-nowrap">{{ $bid['amount'] }}</span></td>
                                        <td class="px-4 py-2.5 text-right text-slate-500 dark:text-slate-400"><x-datetime :value="\Illuminate\Support\Carbon::createFromTimestampMs($bid['at'])" format="j M, g:i A" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800" x-cloak>
                                <template x-for="(bid, index) in feed" :key="bid.seq">
                                    <tr :class="index === 0 && 'bg-brand-50/60 dark:bg-brand-950/40'">
                                        <td class="px-4 py-2.5 text-slate-800 dark:text-slate-200">
                                            <span x-text="bidder(bid)"></span>
                                            <x-badge color="brand" class="ml-2" x-show="index === 0"><span x-text="final ? @js(__('Highest')) : @js(__('Leading'))"></span></x-badge>
                                        </td>
                                        <td class="px-4 py-2.5 text-right font-semibold text-slate-900 dark:text-slate-50"><span class="tabular whitespace-nowrap" x-text="bid.amount"></span></td>
                                        <td class="px-4 py-2.5 text-right text-slate-500 dark:text-slate-400" x-text="time(bid.at)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </x-card>
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400" x-show="bidCount > feed.length" @if ($auction->bid_count <= count($bids)) style="display: none" @endif>
                        {{ __('Showing the latest :count bids.', ['count' => config('auction.realtime.feed_size')]) }}
                    </p>
                </div>
            </section>
        </div>

        {{-- Price and timing --}}
        <aside class="space-y-6 lg:sticky lg:top-20 lg:self-start">
            <x-card>
                <dl class="space-y-5">
                    <div>
                        <dt class="text-sm text-slate-500 dark:text-slate-400">
                            @if ($sold)
                                {{ __('Sold for') }}
                            @elseif ($live)
                                <span x-text="priceLabel">{{ $auction->bid_count > 0 ? __('Current bid') : __('Starting price') }}</span>
                            @elseif ($auction->bid_count > 0)
                                {{ __('Highest bid') }}
                            @else
                                {{ __('Starting price') }}
                            @endif
                        </dt>
                        <dd class="mt-1 font-display text-3xl font-bold text-slate-900 dark:text-slate-50">
                            <x-money :amount="$auction->final_price ?? $auction->current_price" :x-text="$live ? 'price' : null" />
                        </dd>
                        @if ($auction->reserve_price !== null && ! $sold)
                            {{-- The reserve amount itself is never shown. --}}
                            <p class="mt-2 text-sm text-emerald-700 dark:text-emerald-400" x-show="reserveMet" @unless ($auction->reserve_met) style="display: none" @endunless>{{ __('Reserve price met') }}</p>
                            <p class="mt-2 text-sm text-amber-700 dark:text-amber-400" x-show="! reserveMet" @if ($auction->reserve_met) style="display: none" @endif>{{ __('Reserve price not yet met') }}</p>
                        @endif
                    </div>

                    @if ($live && $auction->ends_at)
                        <div class="flex items-center justify-between border-t border-slate-100 pt-4 dark:border-slate-800">
                            <dt class="inline-flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400"><x-app-icon name="clock" class="size-4" /> {{ __('Time left') }}</dt>
                            <dd class="text-lg font-semibold text-slate-900 dark:text-slate-50"><x-countdown :to="$auction->ends_at" x-effect="retarget(endsAt)" /></dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-sm text-slate-500 dark:text-slate-400">{{ __('Next bid from') }}</dt>
                            <dd class="font-semibold text-slate-900 dark:text-slate-50"><x-money :amount="$auction->minimumNextBid()" x-text="nextMinimum" /></dd>
                        </div>
                    @elseif ($scheduled && $auction->starts_at)
                        <div class="flex items-center justify-between border-t border-slate-100 pt-4 dark:border-slate-800">
                            <dt class="inline-flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400"><x-app-icon name="clock" class="size-4" /> {{ __('Starts in') }}</dt>
                            <dd class="text-lg font-semibold text-slate-900 dark:text-slate-50"><x-countdown :to="$auction->starts_at" :done-label="__('Starting')" /></dd>
                        </div>
                    @endif

                    @if ($auction->buy_now_price !== null && ! $auction->status->isFinal() && $auction->bid_count === 0)
                        <div class="flex items-center justify-between" x-show="buyNow">
                            <dt class="text-sm text-slate-500 dark:text-slate-400">{{ __('Buy now price') }}</dt>
                            <dd class="font-semibold text-slate-900 dark:text-slate-50"><x-money :amount="$auction->buy_now_price" /></dd>
                        </div>
                    @endif

                    <div class="space-y-2 border-t border-slate-100 pt-4 text-sm dark:border-slate-800">
                        @if ($auction->starts_at)
                            <div class="flex justify-between gap-4">
                                <dt class="text-slate-500 dark:text-slate-400">{{ $scheduled ? __('Opens') : __('Opened') }}</dt>
                                <dd class="text-right text-slate-800 dark:text-slate-200"><x-datetime :value="$auction->starts_at" /></dd>
                            </div>
                        @endif
                        @if ($auction->ends_at)
                            <div class="flex justify-between gap-4">
                                <dt class="text-slate-500 dark:text-slate-400">{{ $auction->status->isFinal() ? __('Closed') : __('Closes') }}</dt>
                                <dd class="text-right text-slate-800 dark:text-slate-200"><x-datetime :value="$auction->closed_at ?? $auction->ends_at" /></dd>
                            </div>
                        @endif
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Bid increment') }}</dt>
                            <dd class="text-right text-slate-800 dark:text-slate-200"><x-money :amount="$auction->min_increment" /></dd>
                        </div>
                    </div>
                </dl>

                @if ($open)
                    <p class="mt-6 rounded-md bg-slate-100 px-4 py-3 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-300" x-show="status === 'cancelled'" x-cloak>
                        {{ __('This auction was cancelled.') }}
                    </p>
                @endif

                {{-- Where the visitor stands, and the bid form for a buyer. --}}
                <div class="mt-6 space-y-4" x-show="status !== 'cancelled'">
                    @if ($sold)
                        <p class="rounded-md bg-slate-100 px-4 py-3 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                            @if ($auction->winner_id === $viewerId)
                                {{ __('You won this auction.') }}
                                @if ($orderNumber = $auction->order()->value('number'))
                                    <a href="{{ route('orders.show', $orderNumber) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-300">{{ __('View your order') }}</a>
                                @endif
                            @else
                                {{ __('Won by :bidder.', ['bidder' => $auction->winner?->maskedName() ?? __('a bidder')]) }}
                            @endif
                        </p>
                    @elseif ($auction->status === AuctionStatus::Unsold)
                        <p class="rounded-md bg-slate-100 px-4 py-3 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                            {{ $auction->close_reason?->label() ?? __('This auction ended without a sale.') }}
                        </p>
                    @elseif (! $viewerId)
                        <x-button :href="route('login')" class="w-full" size="lg">{{ __('Sign in to bid') }}</x-button>
                        <p class="text-center text-sm text-slate-600 dark:text-slate-400">
                            {{ __('New here?') }}
                            <a href="{{ route('register') }}" class="font-semibold text-brand-700 hover:underline dark:text-brand-300">{{ __('Create a buyer account') }}</a>
                        </p>
                    @elseif (! $viewer['isBuyer'])
                        <p class="rounded-md bg-slate-100 px-4 py-3 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                            {{ __('Seller and staff accounts cannot bid. Use a buyer account.') }}
                        </p>
                    @elseif (! $viewer['verified'])
                        <p class="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-950/60 dark:text-amber-200">
                            {{ __('Confirm your email address to bid.') }}
                            <a href="{{ route('verification.notice') }}" class="font-semibold underline">{{ __('Confirm now') }}</a>
                        </p>
                    @elseif (! $viewer['identityCleared'] && ! $auction->status->isFinal())
                        <p class="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-950/60 dark:text-amber-200">
                            {{ __('Verify your identity to bid. It takes a minute to upload a document.') }}
                            <a href="{{ route('verification') }}" class="font-semibold underline">{{ __('Verify now') }}</a>
                        </p>
                    @elseif ($live && $canBid)
                        {{-- Both are rendered; the live state decides which one shows. --}}
                        <p class="rounded-md bg-brand-50 px-4 py-3 text-sm font-medium text-brand-800 dark:bg-brand-950 dark:text-brand-200" x-show="leading" @unless ($leading) style="display: none" @endunless>
                            {{ __('You are the highest bidder.') }}
                            @if ($leading && $viewer['autoMax'])
                                <span class="block font-normal">{{ __('Your automatic maximum: :amount (only you see this).', ['amount' => $viewer['autoMax']->format()]) }}</span>
                            @endif
                        </p>
                        <p class="rounded-md bg-red-50 px-4 py-3 text-sm font-medium text-red-800 dark:bg-red-950/60 dark:text-red-200" x-show="outbid" @unless ($outbid) style="display: none" @endunless>
                            {{ __('You have been outbid.') }}
                        </p>

                        <form wire:submit="placeBid" class="space-y-3">
                            <div>
                                <label for="bid-amount" class="text-sm font-medium text-slate-700 dark:text-slate-300">
                                    {{ $auto ? __('Your maximum') : __('Your bid') }}
                                </label>
                                <div class="mt-1 flex gap-2">
                                    <input id="bid-amount" type="text" inputmode="decimal" autocomplete="off" wire:model="amount"
                                           placeholder="{{ $auction->minimumNextBid()->toDecimal() }}" :placeholder="nextMinimumInput"
                                           class="form-control tabular min-w-0 flex-1" aria-describedby="bid-help">
                                    <x-button wire:loading.attr="disabled" wire:target="placeBid">
                                        {{ $auto ? __('Set maximum') : __('Place bid') }}
                                    </x-button>
                                </div>
                                <x-input-error :messages="$errors->get('amount')" class="mt-1.5" />
                            </div>

                            <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
                                <input type="checkbox" wire:model.live="auto" class="mt-0.5 rounded border-slate-300 text-brand-600 dark:border-slate-600 dark:bg-slate-800">
                                <span>{{ __('Bid automatically up to this maximum') }}
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ __('We bid for you, one increment at a time, only as far as needed. Your maximum is set aside from your balance and never shown to others.') }}</span>
                                </span>
                            </label>

                            <p id="bid-help" class="text-xs text-slate-500 dark:text-slate-400">
                                @if ($viewer['biddable'])
                                    {{ __('You can bid up to :amount.', ['amount' => $viewer['biddable']->format()]) }}
                                @endif
                                <a href="{{ route('wallet') }}" class="font-semibold text-brand-700 hover:underline dark:text-brand-300">{{ __('Add money') }}</a>
                            </p>
                        </form>

                        @if ($auction->buy_now_price !== null && $auction->bid_count === 0)
                            <div class="border-t border-slate-100 pt-4 dark:border-slate-800" x-show="buyNow">
                                <x-button type="button" variant="secondary" class="w-full"
                                          wire:click="buyNow" wire:loading.attr="disabled" wire:target="buyNow"
                                          wire:confirm="{{ __('Buy it now for :amount? The amount is paid from your wallet into escrow at once.', ['amount' => $auction->buy_now_price->format()]) }}">
                                    {{ __('Buy now for :amount', ['amount' => $auction->buy_now_price->format()]) }}
                                </x-button>
                                <x-input-error :messages="$errors->get('buy_now')" class="mt-1.5" />
                            </div>
                        @endif
                    @elseif ($scheduled)
                        <p class="rounded-md bg-slate-100 px-4 py-3 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                            {{ __('Bidding opens when the auction starts.') }}
                        </p>
                    @endif

                    @if ($viewer && $viewer['isBuyer'] && ! $auction->status->isFinal())
                        <x-button type="button" variant="ghost" size="sm" class="w-full" wire:click="toggleWatch">
                            <x-app-icon name="heart" @class(['size-4', 'fill-current text-red-500' => $viewer['watching']]) />
                            {{ $viewer['watching'] ? __('Watching') : __('Add to watchlist') }}
                        </x-button>
                    @endif
                </div>
            </x-card>

            {{-- Seller --}}
            <x-card>
                <h2 class="font-sans text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Seller') }}</h2>
                <div class="mt-3 flex items-start gap-3">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        <x-app-icon name="building" />
                    </span>
                    <div class="min-w-0">
                        <a href="{{ route('sellers.show', $auction->company) }}" class="font-semibold text-slate-900 hover:text-brand-700 dark:text-slate-50 dark:hover:text-brand-300">{{ $auction->company->name }}</a>
                        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-600 dark:text-slate-400">
                            @if ($auction->company->verified_at)
                                <span class="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-400"><x-app-icon name="shield" class="size-4" /> {{ __('Verified') }}</span>
                            @endif
                            @if ($auction->company->averageRating() !== null)
                                <span class="inline-flex items-center gap-1">
                                    <x-app-icon name="star" class="size-4 text-amber-500" />
                                    {{ number_format($auction->company->averageRating(), 1) }}
                                    <span class="text-slate-400">({{ $auction->company->rating_count }})</span>
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
                <a href="{{ route('sellers.show', $auction->company) }}" class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-brand-700 hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">
                    {{ __('View seller profile') }} <x-app-icon name="arrow-right" class="size-4" />
                </a>
            </x-card>

            <x-card class="text-sm text-slate-600 dark:text-slate-400">
                <ul class="space-y-3">
                    <li class="flex gap-3"><x-app-icon name="shield" class="size-5 text-brand-600 dark:text-brand-400" /> {{ __('The winning bid is held in escrow until you confirm delivery.') }}</li>
                    <li class="flex gap-3"><x-app-icon name="clock" class="size-5 text-brand-600 dark:text-brand-400" /> {{ __('A bid in the final minutes extends the auction.') }}</li>
                    <li class="flex gap-3"><x-app-icon name="truck" class="size-5 text-brand-600 dark:text-brand-400" /> {{ __('The seller ships within :days days of the auction closing.', ['days' => config('auction.orders.ship_by_days')]) }}</li>
                </ul>
            </x-card>
        </aside>
    </div>
</div>
