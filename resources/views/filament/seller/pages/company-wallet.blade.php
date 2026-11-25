@php
    use App\Enums\WithdrawalStatus;

    $wallet = $this->wallet;
    $tz = $this->timezone();
@endphp

<x-filament-panels::page>
    {{-- Balances --}}
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-xl bg-primary-600 p-6 text-white shadow-sm ring-1 ring-primary-700/20 dark:bg-primary-500/20 dark:ring-primary-400/20">
            <p class="text-sm font-medium text-primary-100 dark:text-primary-200">{{ __('Available') }}</p>
            <p class="mt-2 font-display text-3xl font-bold tracking-tight">{{ $wallet->available()->format() }}</p>
            <p class="mt-1 text-xs text-primary-100 dark:text-primary-200">{{ __('Ready to pay out or to pay plan invoices.') }}</p>
        </div>

        @foreach ([
            [__('Total balance'), $wallet->balance, __('Everything in the wallet, including what is on hold.'), 'heroicon-o-scale'],
            [__('On hold'), $wallet->held, __('Payouts waiting to be sent.'), 'heroicon-o-lock-closed'],
        ] as [$label, $amount, $hint, $icon])
            <x-filament::section>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="mt-2 font-display text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ $amount->format() }}</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
                    </div>
                    <x-filament::icon :icon="$icon" class="size-6 text-gray-400 dark:text-gray-500" />
                </div>
            </x-filament::section>
        @endforeach
    </div>

    {{-- Payouts --}}
    <x-filament::section :heading="__('Payouts')" icon="heroicon-o-arrow-up-tray"
        :description="$this->canManage() ? __('Requests need an emailed code. Staff send the money within two working days.') : __('Only the company owner can request payouts.')">
        @if ($this->withdrawals->isEmpty())
            <x-filament::empty-state icon="heroicon-o-arrow-up-tray" :heading="__('No payouts yet')" :description="__('Payouts you request appear here with their status.')" :contained="false" />
        @else
            <ul class="-my-3 divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($this->withdrawals as $withdrawal)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="withdrawal-{{ $withdrawal->id }}">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-gray-950 dark:text-white">
                                {{ $withdrawal->amount->format() }}
                                <span class="font-normal text-gray-500 dark:text-gray-400">→ {{ $withdrawal->destinationSummary() }}</span>
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Requested :date by :name · you receive :net', ['date' => $withdrawal->created_at->timezone($tz)->format('j M Y'), 'name' => $withdrawal->requester?->name ?? '—', 'net' => $withdrawal->net_amount->format()]) }}
                            </p>
                            @if ($withdrawal->rejection_reason)
                                <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $withdrawal->rejection_reason }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <x-filament::badge :color="$this->statusColor($withdrawal->status)">{{ $withdrawal->status->label() }}</x-filament::badge>
                            @if ($this->canManage() && $withdrawal->status === WithdrawalStatus::Pending && $withdrawal->requested_by === auth()->id())
                                {{ ($this->cancelPayoutAction)(['withdrawal' => $withdrawal->id]) }}
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
