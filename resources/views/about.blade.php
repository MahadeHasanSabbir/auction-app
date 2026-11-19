<x-site-layout :title="$page?->title() ?? __('About')" :description="__(':name brings verified sellers and serious buyers together. Bids are backed by money in a wallet, sales are paid through escrow, and the rules are the same for everyone.', ['name' => config('app.name')])">
    <section class="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
        <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 sm:py-14 lg:px-8">
            <h1 class="max-w-3xl text-3xl font-bold text-slate-900 dark:text-white sm:text-4xl">{{ __('An auction house where every bid is real') }}</h1>
            <p class="mt-4 max-w-2xl text-base leading-relaxed text-slate-600 sm:text-lg dark:text-slate-300">
                {{ __(':name brings verified sellers and serious buyers together. Bids are backed by money in a wallet, sales are paid through escrow, and the rules are the same for everyone.', ['name' => config('app.name')]) }}
            </p>
        </div>
    </section>

    <div class="mx-auto max-w-7xl space-y-16 px-4 py-12 sm:px-6 lg:px-8">
        {{-- Staff-written text (admin → Site pages), sanitised by UpdatePage. --}}
        @if ($page?->body())
            <div class="prose-page max-w-3xl">{!! $page->body() !!}</div>
        @endif

        {{-- For buyers / for sellers --}}
        <div class="grid gap-6 lg:grid-cols-2">
            @foreach ([
                [
                    'icon' => 'gavel',
                    'title' => __('For buyers'),
                    'steps' => [
                        [__('Create a free account'), __('Sign up, confirm your email with a code and you are ready.')],
                        [__('Fund your wallet'), __('Your bidding limit is a share of your available balance, so you can never bid money you do not have.')],
                        [__('Bid your way'), __('Bid by hand, or set a maximum and let the system bid for you in the smallest steps needed.')],
                        [__('Receive, then release'), __('Your payment waits in escrow until you confirm the item arrived as described.')],
                    ],
                    'cta' => [route('register'), __('Start bidding')],
                ],
                [
                    'icon' => 'building',
                    'title' => __('For sellers'),
                    'steps' => [
                        [__('Register your company'), __('Create a company account and tell us about your business.')],
                        [__('Bring your team'), __('Choose a plan that fits the number of people who will manage your auctions.')],
                        [__('List and schedule'), __('Add items, propose a schedule and a reserve. Platform staff review each auction before it goes live.')],
                        [__('Ship and get paid'), __('The winner’s payment is already in escrow. It reaches your company wallet when delivery is confirmed.')],
                    ],
                    'cta' => [route('register', ['type' => 'seller']), __('Register as a seller')],
                ],
            ] as $side)
                <x-card class="flex flex-col">
                    <div class="flex items-center gap-3">
                        <span class="flex size-10 items-center justify-center rounded-md bg-brand-100 text-brand-700 dark:bg-brand-900 dark:text-brand-200">
                            <x-app-icon :name="$side['icon']" />
                        </span>
                        <h2 class="text-2xl font-semibold text-slate-900 dark:text-slate-50">{{ $side['title'] }}</h2>
                    </div>

                    <ol class="mt-6 flex-1 space-y-5">
                        @foreach ($side['steps'] as $number => [$title, $text])
                            <li class="flex gap-4">
                                <span class="tabular flex size-7 shrink-0 items-center justify-center rounded-full border border-brand-300 text-sm font-semibold text-brand-800 dark:border-brand-700 dark:text-brand-200" aria-hidden="true">{{ $number + 1 }}</span>
                                <div>
                                    <h3 class="font-semibold text-slate-900 dark:text-slate-50">{{ $title }}</h3>
                                    <p class="mt-1 text-sm leading-relaxed text-slate-600 dark:text-slate-400">{{ $text }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>

                    @guest
                        <div class="mt-6">
                            <x-button :href="$side['cta'][0]">{{ $side['cta'][1] }}</x-button>
                        </div>
                    @endguest
                </x-card>
            @endforeach
        </div>

        {{-- The rules --}}
        <section aria-labelledby="fair-rules">
            <h2 id="fair-rules" class="text-2xl font-semibold text-slate-900 dark:text-slate-50">{{ __('Rules that keep it fair') }}</h2>

            <div class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['wallet', __('Funded bids'), __('The money behind the leading bid is held in the bidder’s wallet until someone outbids them or the auction ends.')],
                    ['clock', __('No sniping'), __('A bid in the final moments extends the auction, so everyone gets a fair chance to answer.')],
                    ['shield', __('Escrow on every sale'), __('The seller is paid only after delivery is confirmed. If something goes wrong, staff step in.')],
                    ['eye', __('Private bidders'), __('Other bidders see a masked name only. Your maximum bid is never shown to anyone.')],
                ] as [$icon, $title, $text])
                    <x-card>
                        <x-app-icon :name="$icon" class="size-6 text-brand-700 dark:text-brand-300" />
                        <h3 class="mt-3 font-semibold text-slate-900 dark:text-slate-50">{{ $title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-400">{{ $text }}</p>
                    </x-card>
                @endforeach
            </div>
        </section>

        <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white px-6 py-8 dark:border-slate-800 dark:bg-slate-900 sm:px-10">
            <div>
                <h2 class="text-2xl font-semibold text-slate-900 dark:text-slate-50">{{ __('See what is on the block') }}</h2>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ __('Browsing is open to everyone. You only need an account to bid.') }}</p>
            </div>
            <x-button :href="route('auctions.index')" size="lg">{{ __('Browse auctions') }} <x-app-icon name="arrow-right" class="size-4" /></x-button>
        </div>
    </div>
</x-site-layout>
