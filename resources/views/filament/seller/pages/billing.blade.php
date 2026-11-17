@php
    use App\Enums\BillingInterval;
    use App\Enums\SubscriptionStatus;

    $company = filament()->getTenant();
    $subscription = $this->subscription;
    $choosing = $this->choosing();
    $canManage = $this->canManage();
    $interval = $this->chosenInterval();
    $tz = $this->timezone();
    $date = fn ($value) => $value?->timezone($tz)->format('j M Y') ?? '—';
@endphp

<x-filament-panels::page>
    @if ($this->notice)
        <x-filament::callout
            :color="$this->notice['type']"
            :icon="$this->notice['type'] === 'success' ? 'heroicon-o-check-circle' : ($this->notice['type'] === 'danger' ? 'heroicon-o-x-circle' : 'heroicon-o-information-circle')"
            :heading="$this->notice['message']"
        />
    @endif

    {{-- The plan the company is on --}}
    @if ($subscription && ! $choosing)
        @php
            $used = $company->seatsUsed();
            $seats = $subscription->maxUsers();
            $statusColor = match ($subscription->status) {
                SubscriptionStatus::Active => 'success',
                SubscriptionStatus::Trialing => 'info',
                default => 'warning',
            };
        @endphp

        <x-filament::section>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Current plan') }}</p>
                    <div class="mt-1 flex flex-wrap items-center gap-3">
                        <h2 class="font-display text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ $subscription->plan->name }}</h2>
                        <x-filament::badge :color="$statusColor">{{ $subscription->status->label() }}</x-filament::badge>
                    </div>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ $subscription->price->format() }} {{ __('per :interval', ['interval' => $this->unit($subscription->billing_interval)]) }}
                    </p>
                </div>
            </div>

            <dl class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        @if ($subscription->status === SubscriptionStatus::Trialing)
                            {{ __('Trial ends') }}
                        @elseif ($subscription->status === SubscriptionStatus::PastDue)
                            {{ __('Payment was due') }}
                        @elseif ($subscription->cancelled_at)
                            {{ __('Ends on') }}
                        @else
                            {{ __('Renews on') }}
                        @endif
                    </dt>
                    <dd class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $date($subscription->current_period_end) }}</dd>
                </div>
                <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Team seats') }}</dt>
                    <dd class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $used }} <span class="text-sm font-normal text-gray-500">/ {{ $seats }}</span></dd>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10" role="presentation">
                        <div @class(['h-full rounded-full', 'bg-primary-600' => $used < $seats, 'bg-warning-500' => $used >= $seats]) style="width: {{ $seats > 0 ? min(100, (int) round($used * 100 / $seats)) : 0 }}%"></div>
                    </div>
                </div>
                <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Active auctions') }}</dt>
                    <dd class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $subscription->maxActiveAuctions() ?? __('Unlimited') }}</dd>
                </div>
                <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Billing') }}</dt>
                    <dd class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $subscription->billing_interval->label() }}</dd>
                </div>
            </dl>

            @if ($subscription->status === SubscriptionStatus::PastDue)
                <x-filament::callout class="mt-4" color="warning" icon="heroicon-o-exclamation-triangle"
                    :heading="__('Your plan payment is overdue')"
                    :description="__('You can keep selling until :date. Pay the open invoice below to continue.', ['date' => $date($subscription->grace_ends_at)])" />
            @elseif ($subscription->cancelled_at)
                <x-filament::callout class="mt-4" color="gray" icon="heroicon-o-calendar"
                    :heading="__('This plan will not renew')"
                    :description="__('You can sell until :date. Use “Keep my plan” to undo.', ['date' => $date($subscription->current_period_end)])" />
            @elseif ($subscription->pendingPlan)
                <x-filament::callout class="mt-4" color="info" icon="heroicon-o-arrows-right-left"
                    :heading="__('Moving to :plan on :date', ['plan' => $subscription->pendingPlan->name, 'date' => $date($subscription->current_period_end)])"
                    :description="__('Billed :interval from then. Choose your current plan below to stay on it.', ['interval' => mb_strtolower(($subscription->pending_billing_interval ?? $subscription->billing_interval)->label())])" />
            @endif
        </x-filament::section>
    @endif

    {{-- Invoices waiting for payment --}}
    @foreach ($this->openInvoices as $invoice)
        @php
            $walletCovers = $this->wallet->available()->greaterThanOrEqual($invoice->amount);
        @endphp
        <x-filament::callout wire:key="open-invoice-{{ $invoice->id }}" color="warning" icon="heroicon-o-document-text"
            :heading="__('Invoice :number · :amount', ['number' => $invoice->number, 'amount' => $invoice->amount->format()])"
            :description="__(':plan, :start – :end. Due :due.', ['plan' => $invoice->plan->name, 'start' => $date($invoice->period_start), 'end' => $date($invoice->period_end), 'due' => $date($invoice->due_at)])">
            <x-slot name="footer">
                @if ($canManage)
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($walletCovers)
                            {{ ($this->payFromWalletAction)(['invoice' => $invoice->id]) }}
                        @endif
                        @foreach ($this->gateways as $name => $driver)
                            {{ ($this->payOnlineAction)(['invoice' => $invoice->id, 'gateway' => $name]) }}
                        @endforeach
                    </div>
                    @if (! $walletCovers && $this->gateways === [])
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Online payment is not available right now, and the company wallet does not cover this invoice.') }}</p>
                    @endif
                @else
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('The company owner can pay it.') }}</p>
                @endif
            </x-slot>
        </x-filament::callout>
    @endforeach

    {{-- The plans --}}
    @if ($canManage && $subscription?->status !== SubscriptionStatus::PastDue)
        <x-filament::section :heading="$choosing ? __('Plans') : __('Change plan')"
            :description="$choosing ? null : __('A dearer plan starts once you pay the difference for the rest of the period. A cheaper one starts when the period ends.')">
            <x-slot name="afterHeader">
                <x-filament::tabs :label="__('Billing interval')">
                    @foreach (BillingInterval::cases() as $option)
                        <x-filament::tabs.item :active="$interval === $option" wire:click="$set('interval', '{{ $option->value }}')">
                            {{ $option->label() }}
                        </x-filament::tabs.item>
                    @endforeach
                </x-filament::tabs>
            </x-slot>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->plans as $plan)
                    @php
                        $price = $plan->priceFor($interval);
                        $current = $this->isCurrent($plan);
                        $saving = $interval === BillingInterval::Yearly ? $this->yearlySaving($plan) : null;
                    @endphp

                    <div wire:key="plan-{{ $plan->id }}-{{ $interval->value }}" @class([
                        'relative flex flex-col rounded-xl border p-6',
                        'border-primary-600 bg-primary-50/50 ring-1 ring-primary-600 dark:border-primary-500 dark:bg-primary-500/5 dark:ring-primary-500' => $current,
                        'border-gray-200 bg-white dark:border-white/10 dark:bg-white/5' => ! $current,
                    ])>
                        @if ($current)
                            {{-- A solid backing so the card border does not show through the translucent badge. --}}
                            <span class="absolute -top-3 left-6 rounded-md bg-white dark:bg-gray-900"><x-filament::badge>{{ __('Your plan') }}</x-filament::badge></span>
                        @endif

                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $plan->name }}</h3>
                        <p class="mt-1 min-h-10 text-sm text-gray-600 dark:text-gray-400">{{ $plan->description }}</p>

                        <p class="mt-5 flex items-baseline gap-1.5">
                            <span class="font-display text-3xl font-bold tracking-tight text-gray-950 dark:text-white">{{ $price?->format() ?? '—' }}</span>
                            <span class="text-sm text-gray-500 dark:text-gray-400">/ {{ $this->unit($interval) }}</span>
                        </p>
                        <p class="mt-1 h-4 text-xs font-medium text-success-600 dark:text-success-400">
                            @if ($saving) {{ __('Save :percent% against paying monthly', ['percent' => $saving]) }} @endif
                        </p>

                        <ul class="mt-5 flex-1 space-y-2.5 text-sm text-gray-700 dark:text-gray-300">
                            @foreach (array_filter([
                                trans_choice(':count team seat|:count team seats', $plan->max_users),
                                $plan->max_active_auctions === null ? __('Unlimited active auctions') : trans_choice(':count active auction|:count active auctions', $plan->max_active_auctions),
                                $this->trialAvailable($plan) ? trans_choice(':count-day free trial|:count-day free trial', $plan->trial_days) : null,
                                ...($plan->features ?? []),
                            ]) as $line)
                                <li class="flex gap-2.5">
                                    <x-filament::icon icon="heroicon-m-check-circle" class="size-5 shrink-0 text-primary-600 dark:text-primary-400" />
                                    <span>{{ $line }}</span>
                                </li>
                            @endforeach
                        </ul>

                        <div class="mt-6">
                            @if ($price === null)
                                <x-filament::button color="gray" disabled class="w-full">{{ __('Only sold :interval', ['interval' => mb_strtolower(BillingInterval::Monthly->label())]) }}</x-filament::button>
                            @elseif ($current && $subscription?->pending_plan_id === null)
                                <x-filament::button color="gray" disabled class="w-full" icon="heroicon-m-check">{{ __('Current plan') }}</x-filament::button>
                            @else
                                {{ ($this->selectPlanAction)(['plan' => $plan->id]) }}
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
