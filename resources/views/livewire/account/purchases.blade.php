<div>
    <x-account.shell :title="__('Purchases')" :description="__('Everything you bought in a period, with refunds, to keep or download.')">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label for="purchases-from" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('From') }}</label>
                <input id="purchases-from" type="date" wire:model.live="from" class="form-control mt-1.5">
            </div>
            <div>
                <label for="purchases-until" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Until') }}</label>
                <input id="purchases-until" type="date" wire:model.live="until" class="form-control mt-1.5">
            </div>
            <x-button variant="secondary" type="button" wire:click="export" class="sm:ms-auto">{{ __('Download CSV') }}</x-button>
        </div>

        <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ($totals as $label => $value)
                <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <dt class="text-sm text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                    <dd class="tabular mt-1 text-lg font-semibold text-slate-900 dark:text-slate-50">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        @if ($orders->isEmpty())
            <x-empty-state :title="__('No purchases in this period')"
                           :description="__('Choose an earlier start date, or win an auction first.')" />
        @else
            <x-card :padding="false" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 text-left text-slate-500 dark:border-slate-800 dark:text-slate-400">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">{{ __('Date') }}</th>
                            <th scope="col" class="px-4 py-3 font-medium">{{ __('Item') }}</th>
                            <th scope="col" class="px-4 py-3 font-medium">{{ __('Status') }}</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">{{ __('Price') }}</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">{{ __('Refunded') }}</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">{{ __('Paid') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($orders as $order)
                            <tr wire:key="purchase-{{ $order->id }}">
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600 dark:text-slate-400"><x-datetime :value="$order->created_at" format="j M Y" /></td>
                                <td class="px-4 py-3">
                                    <a href="{{ route('orders.show', $order) }}" class="font-medium text-slate-900 hover:text-brand-700 dark:text-slate-50 dark:hover:text-brand-300">{{ $order->auction?->title }}</a>
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $order->number }} · {{ $order->company?->name }}</span>
                                </td>
                                <td class="px-4 py-3"><x-order.status :status="$order->status" /></td>
                                <td class="px-4 py-3 text-right"><x-money :amount="$order->amount" /></td>
                                <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-400">
                                    @if ($order->refunded_amount->isZero()) — @else <x-money :amount="$order->refunded_amount" /> @endif
                                </td>
                                <td class="px-4 py-3 text-right font-semibold text-slate-900 dark:text-slate-50"><x-money :amount="\App\Livewire\Account\Purchases::paid($order)" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>

            {{ $orders->links() }}
        @endif
    </x-account.shell>
</div>
