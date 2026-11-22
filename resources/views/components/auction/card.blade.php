@props(['auction'])

{{--
    One auction in a grid. Expects the relations of Auction::scopeForCards()
    to be loaded (lazy loading throws).
--}}
@php
    use App\Enums\AuctionStatus;
    use App\Services\Realtime\AuctionSnapshot;

    $live = $auction->status === AuctionStatus::Live;
    $scheduled = $auction->status === AuctionStatus::Scheduled;
    $category = $auction->product?->category?->name;
@endphp

{{-- An open auction keeps its price, bid count and end time current (liveAuction in resources/js/live.js). --}}

<article @if ($live || $scheduled) x-data="liveAuction(@js(AuctionSnapshot::card($auction)))" @endif {{ $attributes->class('group relative flex flex-col overflow-hidden rounded-lg border border-slate-200 bg-white transition-colors hover:border-brand-400 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-brand-500') }}>
    <div class="relative aspect-[4/3] overflow-hidden bg-slate-100 dark:bg-slate-800">
        <x-product-image :image="$auction->product?->coverImage" :seed="$auction->id" :label="$category" :alt="$auction->title"
                         class="transition duration-300 group-hover:scale-105" />
        <x-auction.status :auction="$auction" class="absolute left-3 top-3" />
        @if ($auction->featured_at !== null && ($live || $scheduled))
            <span class="absolute right-3 top-3 inline-flex items-center gap-1 rounded-md bg-amber-400 px-2 py-0.5 text-xs font-semibold text-amber-950">
                <x-app-icon name="star" class="size-3.5" /> {{ __('Featured') }}
            </span>
        @endif
    </div>

    <div class="flex flex-1 flex-col p-4">
        <p class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ $auction->company->name }}</p>

        <h3 class="mt-1 line-clamp-2 font-sans text-base font-semibold leading-snug text-slate-900 dark:text-slate-50">
            {{-- The whole card is the link target. --}}
            <a href="{{ $auction->url() }}" class="after:absolute after:inset-0">{{ $auction->title }}</a>
        </h3>

        <dl class="mt-auto grid grid-cols-2 gap-3 pt-4 text-sm">
            <div>
                <dt class="text-xs text-slate-500 dark:text-slate-400">
                    @if ($auction->status === AuctionStatus::Sold)
                        {{ __('Sold for') }}
                    @else
                        <span @if ($live) x-text="priceLabel" @endif>{{ $auction->bid_count > 0 ? __('Current bid') : __('Starting price') }}</span>
                    @endif
                </dt>
                <dd class="font-semibold text-slate-900 dark:text-slate-50">
                    <x-money :amount="$auction->final_price ?? $auction->current_price" :x-text="$live ? 'price' : null" />
                </dd>
            </div>

            <div class="text-right">
                @if ($live && $auction->ends_at)
                    <dt class="text-xs text-slate-500 dark:text-slate-400">{{ __('Ends in') }}</dt>
                    <dd class="font-semibold text-slate-900 dark:text-slate-50"><x-countdown :to="$auction->ends_at" x-effect="retarget(endsAt)" /></dd>
                @elseif ($scheduled && $auction->starts_at)
                    <dt class="text-xs text-slate-500 dark:text-slate-400">{{ __('Starts in') }}</dt>
                    <dd class="font-semibold text-slate-900 dark:text-slate-50"><x-countdown :to="$auction->starts_at" :done-label="__('Starting')" /></dd>
                @elseif ($auction->closed_at)
                    <dt class="text-xs text-slate-500 dark:text-slate-400">{{ __('Ended') }}</dt>
                    <dd class="font-semibold text-slate-900 dark:text-slate-50"><x-datetime :value="$auction->closed_at" format="j M Y" /></dd>
                @endif
            </div>
        </dl>

        <p class="mt-3 border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400" @if ($live) x-text="bidCountLabel" @endif>
            {{ trans_choice('{0} No bids yet|{1} :count bid|[2,*] :count bids', $auction->bid_count) }}
        </p>
    </div>
</article>
