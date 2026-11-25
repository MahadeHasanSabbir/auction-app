@php
    use Illuminate\Support\Facades\Storage;

    $rating = $company->averageRating();
    $tabs = [
        'open' => __('Live & upcoming').' ('.($live + $upcoming).')',
        'sold' => __('Sold').' ('.$sold.')',
        'reviews' => __('Reviews').' ('.$company->rating_count.')',
    ];
    // The company's own colour for the storefront header; validated as #rrggbb when saved.
    $accent = $company->accent_color;
@endphp

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <nav aria-label="{{ __('Breadcrumb') }}" class="flex items-center gap-1.5 text-sm text-slate-500 dark:text-slate-400">
        <a href="{{ route('auctions.index') }}" class="hover:text-brand-700 dark:hover:text-brand-300">{{ __('Auctions') }}</a>
        <x-app-icon name="chevron-right" class="size-3.5" />
        <span>{{ __('Sellers') }}</span>
    </nav>

    {{-- Who the seller is --}}
    <x-card class="mt-4 overflow-hidden" :padding="false">
        @if ($company->banner_path)
            <img src="{{ Storage::disk('public')->url($company->banner_path) }}" alt=""
                 class="h-32 w-full object-cover sm:h-48" @if ($accent) style="background-color: {{ $accent }}" @endif>
        @else
            <div @class(['h-20 sm:h-24', 'bg-brand-600' => ! $accent]) @if ($accent) style="background-color: {{ $accent }}" @endif></div>
        @endif

        <div class="p-5 sm:p-6">
        <div class="flex flex-col gap-6 sm:flex-row sm:items-start">
            @if ($company->logo_path)
                <img src="{{ Storage::disk('public')->url($company->logo_path) }}" alt="{{ $company->name }}"
                     class="size-20 shrink-0 rounded-lg border border-slate-200 object-cover dark:border-slate-700">
            @else
                <span class="flex size-20 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                    <x-app-icon name="building" class="size-8" />
                </span>
            @endif

            <div class="min-w-0 flex-1">
                <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-50 sm:text-3xl">{{ $company->name }}</h1>

                <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-600 dark:text-slate-400">
                    @if ($company->isVerified())
                        <span class="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-400"><x-app-icon name="shield" class="size-4" /> {{ __('Verified seller') }}</span>
                    @endif
                    @if ($rating !== null)
                        <span class="inline-flex items-center gap-1">
                            <x-app-icon name="star" class="size-4 text-amber-500" />
                            {{ number_format($rating, 1) }}
                            <span class="text-slate-400">({{ trans_choice('{1} :count rating|[2,*] :count ratings', $company->rating_count) }})</span>
                        </span>
                    @else
                        <span>{{ __('No ratings yet') }}</span>
                    @endif
                    <span>{{ __('Selling since :date', ['date' => $company->created_at?->format('F Y')]) }}</span>
                </div>

                @if ($company->about)
                    <div class="mt-4 max-w-prose whitespace-pre-line leading-relaxed text-slate-700 dark:text-slate-300">{{ $company->about }}</div>
                @endif

                @if ($company->website_url || $company->facebook_url)
                    <div class="mt-4 flex flex-wrap gap-4 text-sm">
                        @if ($company->website_url)
                            <a href="{{ $company->website_url }}" target="_blank" rel="nofollow noopener noreferrer ugc"
                               class="inline-flex items-center gap-1 font-medium text-brand-700 hover:underline dark:text-brand-300">
                                <x-app-icon name="arrow-right" class="size-4" /> {{ __('Website') }}
                            </a>
                        @endif
                        @if ($company->facebook_url)
                            <a href="{{ $company->facebook_url }}" target="_blank" rel="nofollow noopener noreferrer ugc"
                               class="inline-flex items-center gap-1 font-medium text-brand-700 hover:underline dark:text-brand-300">
                                <x-app-icon name="arrow-right" class="size-4" /> {{ __('Facebook') }}
                            </a>
                        @endif
                    </div>
                @endif
            </div>

            <dl class="grid shrink-0 grid-cols-3 gap-3 text-center sm:w-72">
                <div class="rounded-md bg-slate-50 px-3 py-2 dark:bg-slate-800/60">
                    <dt class="text-xs text-slate-500 dark:text-slate-400">{{ __('Live') }}</dt>
                    <dd class="font-display text-xl font-bold text-slate-900 dark:text-slate-50">{{ $live }}</dd>
                </div>
                <div class="rounded-md bg-slate-50 px-3 py-2 dark:bg-slate-800/60">
                    <dt class="text-xs text-slate-500 dark:text-slate-400">{{ __('Upcoming') }}</dt>
                    <dd class="font-display text-xl font-bold text-slate-900 dark:text-slate-50">{{ $upcoming }}</dd>
                </div>
                <div class="rounded-md bg-slate-50 px-3 py-2 dark:bg-slate-800/60">
                    <dt class="text-xs text-slate-500 dark:text-slate-400">{{ __('Sold') }}</dt>
                    <dd class="font-display text-xl font-bold text-slate-900 dark:text-slate-50">{{ $sold }}</dd>
                </div>
            </dl>
        </div>

        @if ($company->suspended_at)
            <p class="mt-4 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-950/60 dark:text-amber-200">
                {{ __('This seller is not taking new listings at the moment. Auctions already running finish as usual.') }}
            </p>
        @endif
        </div>
    </x-card>

    {{-- Their auctions --}}
    <nav aria-label="{{ __('Filter') }}" class="mt-8 flex gap-1 overflow-x-auto overflow-y-hidden border-b border-slate-200 dark:border-slate-800">
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

    @if ($reviews !== null)
        @if ($reviews->isEmpty())
            <x-empty-state class="mt-6" :title="__('No reviews yet')"
                :description="__('Buyers can review a seller once their order is complete.')" />
        @else
            <ul class="mt-6 space-y-4">
                @foreach ($reviews as $review)
                    <li wire:key="review-{{ $review->id }}">
                        <x-card>
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                                <span class="inline-flex items-center gap-0.5" aria-label="{{ __(':count out of 5 stars', ['count' => $review->rating]) }}">
                                    @for ($star = 1; $star <= 5; $star++)
                                        <x-app-icon name="star" @class(['size-4', 'text-amber-500' => $star <= $review->rating, 'text-slate-300 dark:text-slate-600' => $star > $review->rating]) />
                                    @endfor
                                </span>
                                <span class="font-medium text-slate-900 dark:text-slate-100">{{ $review->buyer?->maskedName() ?? __('A buyer') }}</span>
                                <x-datetime :value="$review->created_at" format="j M Y" class="text-slate-500 dark:text-slate-400" />
                            </div>
                            @if ($review->comment)
                                <p class="mt-2 whitespace-pre-line text-slate-700 dark:text-slate-300">{{ $review->comment }}</p>
                            @endif
                            @if ($review->reply)
                                <div class="mt-3 rounded-md bg-slate-50 px-4 py-3 text-sm dark:bg-slate-800/60">
                                    <p class="font-medium text-slate-900 dark:text-slate-100">{{ __('Reply from :name', ['name' => $company->name]) }}</p>
                                    <p class="mt-1 whitespace-pre-line text-slate-700 dark:text-slate-300">{{ $review->reply }}</p>
                                </div>
                            @endif
                        </x-card>
                    </li>
                @endforeach
            </ul>

            <div class="mt-6">
                {{ $reviews->links() }}
            </div>
        @endif
    @elseif ($auctions->isEmpty())
        <x-empty-state class="mt-6"
            :title="$tab === 'sold' ? __('Nothing sold yet') : __('No auctions open right now')"
            :description="__('Check back later, or browse other sellers’ auctions.')" />
    @else
        <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach ($auctions as $auction)
                <x-auction.card :auction="$auction" wire:key="seller-auction-{{ $auction->id }}" />
            @endforeach
        </div>

        <div class="mt-6">
            {{ $auctions->links() }}
        </div>
    @endif
</div>
