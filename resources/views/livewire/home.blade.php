<div>
    {{-- Hero: the pitch and search on the left, the auctions closing next on the right. --}}
    <section class="relative border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
        {{-- A faint dot grid that fades out towards the bottom. --}}
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(var(--color-slate-200)_1px,transparent_1px)] bg-[size:22px_22px] [mask-image:linear-gradient(to_bottom,black,transparent_85%)] dark:bg-[radial-gradient(var(--color-slate-800)_1px,transparent_1px)]" aria-hidden="true"></div>

        <div class="relative mx-auto grid max-w-7xl gap-10 px-4 py-12 sm:px-6 sm:py-16 lg:grid-cols-[1fr_26rem] lg:items-center lg:px-8">
            <div class="max-w-2xl">
                <x-badge color="brand" :pulse="$stats['live'] > 0">{{ trans_choice('{0} New auctions open every week|{1} :count auction live right now|[2,*] :count auctions live right now', $stats['live']) }}</x-badge>

                <h1 class="mt-5 text-4xl font-bold text-slate-900 dark:text-white sm:text-5xl">
                    {{ __('Bid on what you love.') }}
                    <span class="text-brand-600 dark:text-brand-400">{{ __('Sell to buyers who mean it.') }}</span>
                </h1>

                <p class="mt-4 max-w-xl text-base leading-relaxed text-slate-600 dark:text-slate-300 sm:text-lg">
                    {{ __('Timed auctions from verified sellers. Every bid is backed by money in the bidder’s wallet, and every sale is held in escrow until the item arrives.') }}
                </p>

                <form action="{{ route('auctions.index') }}" method="GET" class="mt-7 flex max-w-xl gap-2" role="search">
                    <label for="hero-search" class="sr-only">{{ __('Search auctions') }}</label>
                    <div class="relative flex-1">
                        <x-app-icon name="search" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                        <input id="hero-search" type="search" name="q" placeholder="{{ __('Search for a watch, a car, a coin…') }}" class="form-control py-2.5 pl-10 text-base">
                    </div>
                    <x-button size="lg" class="py-2.5">{{ __('Search') }}</x-button>
                </form>

                <dl class="mt-8 flex flex-wrap divide-x divide-slate-200 dark:divide-slate-800">
                    @foreach ([[$stats['live'], __('Live auctions')], [$stats['sellers'], __('Verified sellers')], [$stats['bids'], __('Bids placed')]] as [$value, $label])
                        <div class="px-6 first:pl-0">
                            <dd class="tabular font-display text-2xl font-bold text-slate-900 dark:text-white">{{ number_format($value) }}</dd>
                            <dt class="text-sm text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                        </div>
                    @endforeach
                </dl>
            </div>

            @if ($endingSoon->isNotEmpty())
                <x-card :padding="false" class="overflow-hidden shadow-xl shadow-slate-900/5 dark:shadow-none">
                    <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                        <h2 class="font-sans text-sm font-semibold tracking-normal text-slate-900 dark:text-slate-100">{{ __('Closing next') }}</h2>
                        <x-badge color="green" pulse>{{ __('Live') }}</x-badge>
                    </div>

                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($endingSoon->take(4) as $auction)
                            <li class="relative flex items-center justify-between gap-4 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/60" wire:key="closing-{{ $auction->id }}"
                                x-data="liveAuction(@js(\App\Services\Realtime\AuctionSnapshot::card($auction)))">
                                <div class="min-w-0">
                                    <a href="{{ route('auctions.show', $auction) }}" class="block truncate text-sm font-medium text-slate-900 after:absolute after:inset-0 dark:text-slate-100">{{ $auction->title }}</a>
                                    <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $auction->company->name }}</p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100"><x-money :amount="$auction->current_price" x-text="price" /></p>
                                    @if ($auction->ends_at)
                                        <p class="text-xs text-slate-500 dark:text-slate-400"><x-countdown :to="$auction->ends_at" x-effect="retarget(endsAt)" /></p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    <a href="{{ route('auctions.index', ['status' => 'live']) }}" class="flex items-center justify-center gap-1 border-t border-slate-200 px-4 py-2.5 text-sm font-medium text-brand-700 hover:bg-slate-50 dark:border-slate-800 dark:text-brand-300 dark:hover:bg-slate-800/60">
                        {{ __('All live auctions') }} <x-app-icon name="arrow-right" class="size-4" />
                    </a>
                </x-card>
            @endif
        </div>
    </section>

    <div class="mx-auto max-w-7xl space-y-14 px-4 py-12 sm:px-6 lg:px-8">
        {{-- Ending soon --}}
        <section aria-labelledby="ending-soon">
            <div class="flex items-end justify-between gap-4">
                <div>
                    <h2 id="ending-soon" class="text-2xl font-semibold text-slate-900 dark:text-slate-50">{{ __('Ending soon') }}</h2>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ __('Live now. The clock is running.') }}</p>
                </div>
                <a href="{{ route('auctions.index', ['status' => 'live']) }}" class="inline-flex shrink-0 items-center gap-1 text-sm font-semibold text-brand-700 hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">
                    {{ __('All live auctions') }} <x-app-icon name="arrow-right" class="size-4" />
                </a>
            </div>

            @if ($endingSoon->isEmpty())
                <x-empty-state class="mt-6" icon="clock" :title="__('Nothing is live at the moment')" :description="__('New auctions are scheduled all the time. Have a look at what is opening soon.')" />
            @else
                <div class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($endingSoon as $auction)
                        <x-auction.card :auction="$auction" wire:key="live-{{ $auction->id }}" />
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Categories --}}
        @if ($categories->isNotEmpty())
            <section aria-labelledby="categories">
                <h2 id="categories" class="text-2xl font-semibold text-slate-900 dark:text-slate-50">{{ __('Browse by category') }}</h2>

                <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ($categories as $category)
                        <a href="{{ route('auctions.index', ['category' => $category->slug]) }}"
                           class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-800 transition-colors hover:border-brand-400 hover:text-brand-700 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100 dark:hover:border-brand-500 dark:hover:text-brand-300">
                            {{ $category->name }}
                            <x-app-icon name="chevron-right" class="size-4 text-slate-400" />
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Opening soon --}}
        @if ($openingSoon->isNotEmpty())
            <section aria-labelledby="opening-soon">
                <div class="flex items-end justify-between gap-4">
                    <div>
                        <h2 id="opening-soon" class="text-2xl font-semibold text-slate-900 dark:text-slate-50">{{ __('Opening soon') }}</h2>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ __('Scheduled auctions you can study before the bidding starts.') }}</p>
                    </div>
                    <a href="{{ route('auctions.index', ['status' => 'upcoming']) }}" class="inline-flex shrink-0 items-center gap-1 text-sm font-semibold text-brand-700 hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">
                        {{ __('All upcoming') }} <x-app-icon name="arrow-right" class="size-4" />
                    </a>
                </div>

                <div class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($openingSoon as $auction)
                        <x-auction.card :auction="$auction" wire:key="soon-{{ $auction->id }}" />
                    @endforeach
                </div>
            </section>
        @endif

        {{-- How it works --}}
        <section aria-labelledby="how-it-works">
            <h2 id="how-it-works" class="text-2xl font-semibold text-slate-900 dark:text-slate-50">{{ __('How buying works') }}</h2>

            <ol class="mt-6 grid gap-6 md:grid-cols-3">
                @foreach ([
                    ['wallet', __('Fund your wallet'), __('Add money by card or mobile banking. You can bid up to a set share of your balance, so a bid is never an empty promise.')],
                    ['gavel', __('Bid, or let us bid for you'), __('Place a bid yourself or set your maximum and we raise your bid only as far as needed. A late bid extends the auction, so nobody wins by sniping.')],
                    ['shield', __('Pay through escrow'), __('When you win, the price moves into escrow. The seller is paid after you confirm the item arrived as described.')],
                ] as $index => [$icon, $title, $text])
                    <li>
                        <x-card class="h-full">
                            <div class="flex items-center gap-3">
                                <span class="flex size-10 items-center justify-center rounded-md bg-brand-100 text-brand-700 dark:bg-brand-900 dark:text-brand-200">
                                    <x-app-icon :name="$icon" />
                                </span>
                                <span class="text-sm font-semibold text-slate-500 dark:text-slate-400">{{ __('Step :number', ['number' => $index + 1]) }}</span>
                            </div>
                            <h3 class="mt-4 font-semibold text-slate-900 dark:text-slate-50">{{ $title }}</h3>
                            <p class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-400">{{ $text }}</p>
                        </x-card>
                    </li>
                @endforeach
            </ol>
        </section>

        {{-- Sellers --}}
        <section class="overflow-hidden rounded-xl bg-slate-900 px-6 py-10 text-white sm:px-10 sm:py-12 dark:border dark:border-slate-800">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                <div class="max-w-2xl">
                    <h2 class="text-2xl font-semibold">{{ __('Run your auctions here') }}</h2>
                    <p class="mt-3 text-slate-300">
                        {{ __('Register your company, add your team and list your items. Buyers arrive with funded wallets, and you are paid from escrow as soon as delivery is confirmed.') }}
                    </p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-3">
                    <a href="{{ route('register', ['type' => 'seller']) }}" class="inline-flex items-center justify-center rounded-md bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-brand-500">
                        {{ __('Register as a seller') }}
                    </a>
                    <a href="{{ route('about') }}" class="inline-flex items-center justify-center rounded-md border border-slate-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">
                        {{ __('Learn more') }}
                    </a>
                </div>
            </div>
        </section>
    </div>
</div>
