<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-50">{{ __('Auctions') }}</h1>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400" aria-live="polite">
                {{ trans_choice('{0} No auctions match|{1} :count auction|[2,*] :count auctions', $auctions->total()) }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <label for="sort" class="text-sm text-slate-600 dark:text-slate-400">{{ __('Sort by') }}</label>
            <select id="sort" wire:model.live="sort" class="form-control w-auto py-2 pr-9">
                <option value="ending">{{ __('Ending soonest') }}</option>
                <option value="newest">{{ __('Newly listed') }}</option>
                <option value="price_low">{{ __('Price: low to high') }}</option>
                <option value="price_high">{{ __('Price: high to low') }}</option>
                <option value="bids">{{ __('Most bids') }}</option>
            </select>
        </div>
    </div>

    <div class="mt-6 grid gap-8 lg:grid-cols-[17rem_1fr]" x-data="{ filters: false }">
        {{-- Filters: always visible on wide screens, collapsible below. --}}
        <aside aria-label="{{ __('Filters') }}">
            <button type="button" @click="filters = ! filters" :aria-expanded="filters"
                    class="flex w-full items-center justify-between rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 lg:hidden">
                <span class="inline-flex items-center gap-2"><x-app-icon name="funnel" class="size-4" /> {{ __('Search and filters') }}</span>
                <x-app-icon name="chevron-down" class="size-4 transition" ::class="filters && 'rotate-180'" />
            </button>

            <form wire:submit.prevent class="mt-4 space-y-6 lg:mt-0 lg:block" :class="filters ? 'block' : 'hidden'">
                <div>
                    <label for="search" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Search') }}</label>
                    <div class="relative mt-1.5">
                        <x-app-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                        <input id="search" type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Title or product') }}" class="form-control pl-9">
                    </div>
                </div>

                <fieldset>
                    <legend class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Status') }}</legend>
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        @foreach (['open' => __('Open'), 'live' => __('Live'), 'upcoming' => __('Upcoming'), 'ended' => __('Ended')] as $value => $label)
                            <label class="cursor-pointer">
                                <input type="radio" wire:model.live="status" value="{{ $value }}" class="peer sr-only">
                                <span class="block rounded-md border border-slate-300 px-3 py-2 text-center text-sm font-medium text-slate-700 peer-checked:border-brand-600 peer-checked:bg-brand-50 peer-checked:text-brand-800 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand-500 dark:border-slate-700 dark:text-slate-300 dark:peer-checked:border-brand-400 dark:peer-checked:bg-brand-950 dark:peer-checked:text-brand-200">
                                    {{ $label }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div>
                    <label for="category" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Category') }}</label>
                    <select id="category" wire:model.live="category" class="form-control mt-1.5">
                        <option value="">{{ __('All categories') }}</option>
                        @foreach ($categories as $parent)
                            <option value="{{ $parent->slug }}">{{ $parent->name }}</option>
                            @foreach ($parent->children as $child)
                                <option value="{{ $child->slug }}">&nbsp;&nbsp;&nbsp;{{ $child->name }}</option>
                            @endforeach
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="company" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Seller') }}</label>
                    <select id="company" wire:model.live="company" class="form-control mt-1.5">
                        <option value="">{{ __('All sellers') }}</option>
                        @foreach ($companies as $seller)
                            <option value="{{ $seller->slug }}">{{ $seller->name }}</option>
                        @endforeach
                    </select>
                </div>

                <fieldset>
                    <legend class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Price (:currency)', ['currency' => config('auction.currency')]) }}</legend>
                    <div class="mt-1.5 flex items-center gap-2">
                        <label for="min-price" class="sr-only">{{ __('Lowest price') }}</label>
                        <input id="min-price" type="text" inputmode="decimal" wire:model.live.debounce.600ms="minPrice" placeholder="{{ __('Min') }}" class="form-control">
                        <span class="text-slate-400" aria-hidden="true">–</span>
                        <label for="max-price" class="sr-only">{{ __('Highest price') }}</label>
                        <input id="max-price" type="text" inputmode="decimal" wire:model.live.debounce.600ms="maxPrice" placeholder="{{ __('Max') }}" class="form-control">
                    </div>
                </fieldset>

                @if ($hasFilters)
                    <x-button type="button" variant="secondary" size="sm" class="w-full" wire:click="clearFilters">{{ __('Clear filters') }}</x-button>
                @endif
            </form>

            @if ($canSaveSearch)
                <div class="mt-6 rounded-lg border border-slate-200 p-4 dark:border-slate-800" :class="filters ? 'block' : 'hidden lg:block'"
                     x-data="{ open: false }" x-on:search-saved.window="open = false">
                    <p class="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
                        <x-app-icon name="bell" class="size-4 text-brand-600 dark:text-brand-400" /> {{ __('Get alerts for this search') }}
                    </p>
                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">{{ __('We will tell you when a new auction matches.') }}</p>

                    @auth
                        <x-button type="button" size="sm" class="mt-3 w-full" x-show="! open" x-on:click="open = true">{{ __('Save this search') }}</x-button>

                        <form wire:submit="saveSearch" class="mt-3 space-y-2" x-show="open" x-cloak>
                            <label for="search-name" class="sr-only">{{ __('Name') }}</label>
                            <input id="search-name" type="text" wire:model="searchName" maxlength="100" placeholder="{{ $savedSearchName }}" class="form-control">
                            @error('searchName') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            <x-button type="submit" size="sm" class="w-full" wire:loading.attr="disabled" wire:target="saveSearch">{{ __('Save') }}</x-button>
                        </form>
                    @else
                        <x-button :href="route('login')" size="sm" variant="secondary" class="mt-3 w-full">{{ __('Sign in to save this search') }}</x-button>
                    @endauth
                </div>
            @endif
        </aside>

        <section aria-label="{{ __('Results') }}" class="min-w-0">
            {{-- Dims while a filter change is loading. --}}
            <div wire:loading.class="opacity-50" class="transition-opacity">
                @if ($auctions->isEmpty())
                    <x-empty-state icon="search" :title="__('No auctions match these filters')" :description="__('Try a different search, a wider price range or another status.')">
                        @if ($hasFilters)
                            <x-button type="button" variant="secondary" wire:click="clearFilters">{{ __('Clear filters') }}</x-button>
                        @endif
                    </x-empty-state>
                @else
                    <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($auctions as $auction)
                            <x-auction.card :auction="$auction" wire:key="auction-{{ $auction->id }}" />
                        @endforeach
                    </div>

                    <div class="mt-8">
                        {{ $auctions->links() }}
                    </div>
                @endif
            </div>
        </section>
    </div>
</div>
