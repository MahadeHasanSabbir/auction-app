<div>
    <x-account.shell :title="__('My orders')" :description="__('What you won or bought, from payment to delivery.')">
        <nav aria-label="{{ __('Filter') }}" class="flex gap-1 overflow-x-auto border-b border-slate-200 dark:border-slate-800">
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

        @if ($orders->isEmpty())
            <x-empty-state
                :title="$tab === 'open' ? __('No orders in progress') : __('No finished orders yet')"
                :description="__('When you win an auction or buy it now, the order shows up here.')">
                <x-button :href="route('auctions.index')">{{ __('Browse auctions') }}</x-button>
            </x-empty-state>
        @else
            <x-card :padding="false" class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($orders as $order)
                    <a href="{{ route('orders.show', $order) }}" wire:key="order-{{ $order->id }}"
                       class="flex flex-wrap items-center gap-4 px-5 py-4 hover:bg-slate-50 dark:hover:bg-slate-800/50">
                        <span class="size-14 shrink-0 overflow-hidden rounded-md bg-slate-100 dark:bg-slate-800">
                            <x-product-image :image="$order->auction?->product?->coverImage" :seed="$order->auction_id" :alt="$order->auction?->title" />
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold text-slate-900 dark:text-slate-50">{{ $order->auction?->title }}</span>
                            <span class="block text-sm text-slate-500 dark:text-slate-400">
                                {{ $order->number }} · {{ $order->company->name }}
                            </span>
                        </span>

                        <span class="flex items-center gap-4 text-sm">
                            <x-order.status :status="$order->status" />
                            <span class="text-right">
                                <x-money :amount="$order->amount" class="font-semibold text-slate-900 dark:text-slate-50" />
                                <x-datetime :value="$order->created_at" format="j M Y" class="block text-xs text-slate-500 dark:text-slate-400" />
                            </span>
                        </span>
                    </a>
                @endforeach
            </x-card>

            {{ $orders->links() }}
        @endif
    </x-account.shell>
</div>
