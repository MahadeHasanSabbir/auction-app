<div>
    <x-account.shell :title="__('Saved searches')" :description="__('We tell you when a new auction matches a search with alerts on. Save a search from the auction list.')">
        @if ($searches->isEmpty())
            <x-empty-state icon="search" :title="__('No saved searches yet')"
                           :description="__('Filter the auction list by a word, category, seller or price, then press “Save this search”.')">
                <x-button :href="route('auctions.index')">{{ __('Browse auctions') }}</x-button>
            </x-empty-state>
        @else
            <p class="text-sm text-slate-600 dark:text-slate-400">{{ __(':count of :max saved searches', ['count' => $searches->count(), 'max' => $max]) }}</p>

            <x-card :padding="false">
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($searches as $search)
                        @php $criteria = $search->criteria(); @endphp
                        <li wire:key="search-{{ $search->id }}" class="flex flex-wrap items-center justify-between gap-4 px-4 py-4">
                            <div class="min-w-0">
                                <a href="{{ $criteria->url() }}" class="font-medium text-slate-900 hover:underline dark:text-slate-50">{{ $search->name }}</a>
                                <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-400">{{ $criteria->summary($categoryNames, $companyNames) }}</p>
                                @if ($search->last_alerted_at)
                                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-500">{{ __('Last match') }}: <x-datetime :value="$search->last_alerted_at" format="j M Y, H:i" /></p>
                                @endif
                            </div>

                            <div class="flex items-center gap-2">
                                <button type="button" wire:click="toggleAlerts({{ $search->id }})" role="switch" aria-checked="{{ $search->alerts ? 'true' : 'false' }}"
                                        class="inline-flex items-center gap-2 rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                                    <x-app-icon name="bell" @class(['size-4', 'text-brand-600 dark:text-brand-400' => $search->alerts, 'text-slate-400' => ! $search->alerts]) />
                                    {{ $search->alerts ? __('Alerts on') : __('Alerts off') }}
                                </button>
                                <x-button type="button" variant="secondary" size="sm" wire:click="delete({{ $search->id }})"
                                          wire:confirm="{{ __('Delete this saved search?') }}">{{ __('Delete') }}</x-button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        @endif
    </x-account.shell>
</div>
