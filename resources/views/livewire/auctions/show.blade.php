@php
    use App\Enums\AuctionStatus;

    $product = $auction->product;
    $images = $product?->images ?? collect();
    $category = $product?->category;
    $live = $auction->status === AuctionStatus::Live;
    $scheduled = $auction->status === AuctionStatus::Scheduled;
    $sold = $auction->status === AuctionStatus::Sold;
@endphp

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
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
                    <span class="font-normal text-slate-500 dark:text-slate-400">({{ $auction->bid_count }})</span>
                </h2>

                @if ($bids->isEmpty())
                    <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">
                        {{ $scheduled ? __('Bidding has not started yet.') : __('No bids were placed.') }}
                    </p>
                @else
                    <x-card :padding="false" class="mt-3 overflow-hidden">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                                <tr>
                                    <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('Bidder') }}</th>
                                    <th scope="col" class="px-4 py-2.5 text-right font-semibold">{{ __('Amount') }}</th>
                                    <th scope="col" class="px-4 py-2.5 text-right font-semibold">{{ __('When') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                @foreach ($bids as $bid)
                                    <tr wire:key="bid-{{ $bid->id }}" @class(['bg-brand-50/60 dark:bg-brand-950/40' => $loop->first])>
                                        <td class="px-4 py-2.5 text-slate-800 dark:text-slate-200">
                                            {{-- Other bidders only ever see a masked name. --}}
                                            {{ $bid->user_id === $viewerId ? __('You') : $bid->bidder->maskedName() }}
                                            @if ($loop->first)
                                                <x-badge color="brand" class="ml-2">{{ $live ? __('Leading') : __('Highest') }}</x-badge>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 text-right font-semibold text-slate-900 dark:text-slate-50"><x-money :amount="$bid->amount" /></td>
                                        <td class="px-4 py-2.5 text-right text-slate-500 dark:text-slate-400"><x-datetime :value="$bid->created_at" format="j M, g:i A" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-card>
                    @if ($auction->bid_count > $bids->count())
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ __('Showing the latest :count bids.', ['count' => $bids->count()]) }}</p>
                    @endif
                @endif
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
                            @elseif ($auction->bid_count > 0)
                                {{ $live ? __('Current bid') : __('Highest bid') }}
                            @else
                                {{ __('Starting price') }}
                            @endif
                        </dt>
                        <dd class="mt-1 font-display text-3xl font-bold text-slate-900 dark:text-slate-50">
                            <x-money :amount="$auction->final_price ?? $auction->current_price" />
                        </dd>
                        @if ($auction->reserve_price !== null && ! $sold)
                            {{-- The reserve amount itself is never shown. --}}
                            <p class="mt-2 text-sm {{ $auction->reserve_met ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400' }}">
                                {{ $auction->reserve_met ? __('Reserve price met') : __('Reserve price not yet met') }}
                            </p>
                        @endif
                    </div>

                    @if ($live && $auction->ends_at)
                        <div class="flex items-center justify-between border-t border-slate-100 pt-4 dark:border-slate-800">
                            <dt class="inline-flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400"><x-app-icon name="clock" class="size-4" /> {{ __('Time left') }}</dt>
                            <dd class="text-lg font-semibold text-slate-900 dark:text-slate-50"><x-countdown :to="$auction->ends_at" /></dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-sm text-slate-500 dark:text-slate-400">{{ __('Next bid from') }}</dt>
                            <dd class="font-semibold text-slate-900 dark:text-slate-50"><x-money :amount="$auction->minimumNextBid()" /></dd>
                        </div>
                    @elseif ($scheduled && $auction->starts_at)
                        <div class="flex items-center justify-between border-t border-slate-100 pt-4 dark:border-slate-800">
                            <dt class="inline-flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400"><x-app-icon name="clock" class="size-4" /> {{ __('Starts in') }}</dt>
                            <dd class="text-lg font-semibold text-slate-900 dark:text-slate-50"><x-countdown :to="$auction->starts_at" :done-label="__('Starting')" /></dd>
                        </div>
                    @endif

                    @if ($auction->buy_now_price !== null && ! $auction->status->isFinal())
                        <div class="flex items-center justify-between">
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

                {{-- Bidding itself arrives with the auction engine; for now this states where the visitor stands. --}}
                <div class="mt-6">
                    @if ($sold)
                        <p class="rounded-md bg-slate-100 px-4 py-3 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                            @if ($auction->winner_id === $viewerId)
                                {{ __('You won this auction.') }}
                            @else
                                {{ __('Won by :bidder.', ['bidder' => $auction->winner?->maskedName() ?? __('a bidder')]) }}
                            @endif
                        </p>
                    @elseif ($auction->status === AuctionStatus::Unsold)
                        <p class="rounded-md bg-slate-100 px-4 py-3 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                            {{ $auction->close_reason?->label() ?? __('This auction ended without a sale.') }}
                        </p>
                    @elseif ($live && $auction->leader_id !== null && $auction->leader_id === $viewerId)
                        <p class="rounded-md bg-brand-50 px-4 py-3 text-sm font-medium text-brand-800 dark:bg-brand-950 dark:text-brand-200">
                            {{ __('You are the highest bidder.') }}
                        </p>
                    @elseif (! $viewerId)
                        <x-button :href="route('login')" class="w-full" size="lg">{{ __('Sign in to bid') }}</x-button>
                        <p class="mt-3 text-center text-sm text-slate-600 dark:text-slate-400">
                            {{ __('New here?') }}
                            <a href="{{ route('register') }}" class="font-semibold text-brand-700 hover:underline dark:text-brand-300">{{ __('Create a buyer account') }}</a>
                        </p>
                    @else
                        <p class="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-950/60 dark:text-amber-200">
                            {{ __('Online bidding is being set up and will open here shortly.') }}
                        </p>
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
                        <p class="font-semibold text-slate-900 dark:text-slate-50">{{ $auction->company->name }}</p>
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
                <a href="{{ route('auctions.index', ['company' => $auction->company->slug]) }}" class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-brand-700 hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">
                    {{ __('More from this seller') }} <x-app-icon name="arrow-right" class="size-4" />
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
