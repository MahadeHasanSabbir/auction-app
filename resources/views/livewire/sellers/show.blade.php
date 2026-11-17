@php
    use Illuminate\Support\Facades\Storage;

    $rating = $company->averageRating();
    $tabs = [
        'open' => __('Live & upcoming').' ('.($live + $upcoming).')',
        'sold' => __('Sold').' ('.$sold.')',
    ];
@endphp

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <nav aria-label="{{ __('Breadcrumb') }}" class="flex items-center gap-1.5 text-sm text-slate-500 dark:text-slate-400">
        <a href="{{ route('auctions.index') }}" class="hover:text-brand-700 dark:hover:text-brand-300">{{ __('Auctions') }}</a>
        <x-app-icon name="chevron-right" class="size-3.5" />
        <span>{{ __('Sellers') }}</span>
    </nav>

    {{-- Who the seller is --}}
    <x-card class="mt-4">
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
                    @if ($company->verified_at)
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
    </x-card>

    {{-- Their auctions --}}
    <nav aria-label="{{ __('Filter') }}" class="mt-8 flex gap-1 overflow-x-auto border-b border-slate-200 dark:border-slate-800">
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
