<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Auctions') }}
        </h2>
    </x-slot>

    <div class="py-6">
        @if ($auctions->isEmpty())
            <div class="max-w-3xl mx-auto bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-10 text-center text-gray-600 dark:text-gray-400">
                {{ __('There are no live or upcoming auctions right now.') }}
            </div>
        @else
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($auctions as $auction)
                    @php($live = $auction->status === \App\Enums\AuctionStatus::Live)
                    @php($cover = $auction->product?->coverImage)

                    <article class="flex flex-col overflow-hidden bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
                        <div class="aspect-video bg-gray-200 dark:bg-gray-700">
                            @if ($cover)
                                <img src="{{ Storage::disk('public')->url($cover->thumbnail_path ?? $cover->path) }}" alt="{{ $cover->alt ?? $auction->title }}" class="h-full w-full object-cover" loading="lazy">
                            @endif
                        </div>

                        <div class="flex flex-1 flex-col p-4 text-gray-900 dark:text-gray-100">
                            <div class="flex items-center justify-between text-xs">
                                <span class="rounded-full px-2 py-0.5 font-semibold {{ $live ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">
                                    {{ $auction->status->label() }}
                                </span>
                                <span class="text-gray-500 dark:text-gray-400">{{ $auction->product?->category?->name }}</span>
                            </div>

                            <h3 class="mt-2 font-semibold leading-snug">{{ $auction->title }}</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $auction->company->name }}</p>

                            <dl class="mt-4 grid grid-cols-2 gap-2 text-sm">
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">{{ $live && $auction->bid_count > 0 ? __('Current bid') : __('Starting price') }}</dt>
                                    <dd class="font-semibold">{{ $auction->current_price->format() }}</dd>
                                </div>
                                <div class="text-right">
                                    <dt class="text-gray-500 dark:text-gray-400">{{ $live ? __('Ends') : __('Starts') }}</dt>
                                    <dd class="font-semibold">{{ ($live ? $auction->ends_at : $auction->starts_at)?->diffForHumans() }}</dd>
                                </div>
                            </dl>

                            @if ($live)
                                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                                    {{ trans_choice('{0} No bids yet|{1} :count bid|[2,*] :count bids', $auction->bid_count) }}
                                </p>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $auctions->links() }}
            </div>
        @endif
    </div>
</x-app-layout>
