<div>
    <x-account.shell :title="__('Invoices and receipts')" :description="__('A numbered document for every purchase, deposit, withdrawal and fine. Download any of them as a PDF.')">
        @if ($invoices->isEmpty())
            <x-empty-state :title="__('No documents yet')"
                           :description="__('Receipts appear here once you add money, withdraw or complete a purchase.')" />
        @else
            <x-card :padding="false" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 text-left text-slate-500 dark:border-slate-800 dark:text-slate-400">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">{{ __('Issued') }}</th>
                            <th scope="col" class="px-4 py-3 font-medium">{{ __('Document') }}</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">{{ __('Total') }}</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('Download') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($invoices as $invoice)
                            <tr wire:key="invoice-{{ $invoice->id }}">
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600 dark:text-slate-400"><x-datetime :value="$invoice->issued_at" format="j M Y" /></td>
                                <td class="px-4 py-3">
                                    <span class="font-medium text-slate-900 dark:text-slate-50">{{ $invoice->kind->label() }}</span>
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $invoice->number }}</span>
                                </td>
                                <td class="px-4 py-3 text-right font-semibold text-slate-900 dark:text-slate-50"><x-money :amount="$invoice->total" /></td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('invoices.pdf', $invoice) }}" class="inline-flex items-center gap-1.5 font-medium text-brand-700 hover:underline dark:text-brand-300">
                                        <x-app-icon name="download" class="size-4" /> PDF
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>

            {{ $invoices->links() }}
        @endif
    </x-account.shell>
</div>
