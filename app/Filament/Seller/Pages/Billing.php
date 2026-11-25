<?php

namespace App\Filament\Seller\Pages;

use App\Actions\Billing\ApplyInvoiceCoupon;
use App\Actions\Billing\CancelSubscription;
use App\Actions\Billing\ChangePlan;
use App\Actions\Billing\PayInvoice;
use App\Actions\Billing\StartInvoicePayment;
use App\Actions\Billing\SubscribeToPlan;
use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Seller\Pages\Concerns\ForCompany;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Wallet;
use App\Services\Payments\CheckoutUrls;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentGateways;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * The company's plan: choose one when signing up, change or cancel it, pay
 * invoices and see what has been billed. Everyone on the team can look; the
 * company owner changes things.
 *
 * @property-read Subscription|null $subscription
 * @property-read Collection<int, Plan> $plans
 * @property-read Collection<int, SubscriptionInvoice> $openInvoices
 * @property-read array<string, PaymentGateway> $gateways
 * @property-read Wallet $wallet
 * @property-read array{type: string, message: string}|null $notice
 */
class Billing extends Page implements HasTable
{
    use ForCompany;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'billing';

    protected string $view = 'filament.seller.pages.billing';

    /** Our reference of a payment the payer is returning from (PaymentCallbackController). */
    #[Url(except: '')]
    public string $payment = '';

    #[Url(except: '')]
    public string $outcome = '';

    /** The billing interval the plan prices are shown for. */
    public string $interval = 'monthly';

    public static function getNavigationLabel(): string
    {
        return __('Plan & billing');
    }

    public function getTitle(): string
    {
        return __('Plan & billing');
    }

    public function getSubheading(): ?string
    {
        return $this->choosing()
            ? __('Pick the plan that fits your team. You can change it at any time.')
            : null;
    }

    public function mount(): void
    {
        $this->interval = $this->subscription?->billing_interval->value ?? 'monthly';
    }

    #[Computed]
    public function subscription(): ?Subscription
    {
        return Subscription::query()->with(['plan', 'pendingPlan'])->where('company_id', $this->company()->id)->first();
    }

    /** @return Collection<int, Plan> */
    #[Computed]
    public function plans(): Collection
    {
        return Plan::query()->where('is_active', true)->where('is_public', true)->orderBy('sort_order')->get();
    }

    /** @return Collection<int, SubscriptionInvoice> */
    #[Computed]
    public function openInvoices(): Collection
    {
        return SubscriptionInvoice::query()->with('plan')->where('company_id', $this->company()->id)->where('status', InvoiceStatus::Open)->latest('id')->get();
    }

    /** @return array<string, PaymentGateway> */
    #[Computed]
    public function gateways(): array
    {
        return app(PaymentGateways::class)->all();
    }

    #[Computed]
    public function wallet(): Wallet
    {
        return Wallet::query()->where('company_id', $this->company()->id)->firstOrFail();
    }

    /** @return array{type: string, message: string}|null */
    #[Computed]
    public function notice(): ?array
    {
        $invoice = $this->payment === ''
            ? null
            : SubscriptionInvoice::query()->where('company_id', $this->company()->id)->where('tran_id', $this->payment)->first();

        return match (true) {
            $invoice?->status === InvoiceStatus::Paid => ['type' => 'success', 'message' => __('Payment received. Thank you.')],
            $invoice === null => null,
            $this->outcome === 'failed' => ['type' => 'danger', 'message' => __('The payment did not go through. Nothing was charged.')],
            $this->outcome === 'cancelled' => ['type' => 'gray', 'message' => __('You cancelled the payment. Nothing was charged.')],
            default => ['type' => 'info', 'message' => __('We are waiting for the payment gateway to confirm your payment.')],
        };
    }

    /** No plan yet, the first invoice is unpaid, or the plan ended: the page offers plans to choose from. */
    public function choosing(): bool
    {
        $status = $this->subscription?->status;

        return $status === null || $status === SubscriptionStatus::Incomplete || $status === SubscriptionStatus::Expired;
    }

    public function trialAvailable(Plan $plan): bool
    {
        return $plan->trial_days > 0 && ! ($this->subscription?->hadTrial() ?? false);
    }

    public function chosenInterval(): BillingInterval
    {
        return BillingInterval::tryFrom($this->interval) ?? BillingInterval::Monthly;
    }

    /** What a year saves against twelve months, in whole percent; null when nothing. */
    public function yearlySaving(Plan $plan): ?int
    {
        $monthly = $plan->price_monthly->amount * 12;
        $yearly = $plan->price_yearly?->amount;

        return $yearly === null || $monthly === 0 || $yearly >= $monthly ? null : (int) round(100 - $yearly * 100 / $monthly);
    }

    /** The current plan exactly as shown (same plan and interval). */
    public function isCurrent(Plan $plan): bool
    {
        return ! $this->choosing()
            && $this->subscription?->plan_id === $plan->id
            && $this->subscription->billing_interval === $this->chosenInterval();
    }

    public function unit(BillingInterval $interval): string
    {
        return $interval === BillingInterval::Yearly ? __('year') : __('month');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(SubscriptionInvoice::query()->with('plan')->where('company_id', $this->company()->id)->where('status', '!=', InvoiceStatus::Void))
            ->heading(__('Invoices'))
            ->defaultSort('id', 'desc')
            ->paginated([10, 25])
            ->emptyStateIcon(Heroicon::OutlinedDocumentText)
            ->emptyStateHeading(__('Nothing billed yet'))
            ->emptyStateDescription(__('Invoices for your plan appear here.'))
            ->columns([
                TextColumn::make('number')->label(__('Invoice'))->weight('medium'),
                TextColumn::make('plan.name')->label(__('Plan'))->description(fn (SubscriptionInvoice $record) => $record->billing_interval->label()),
                TextColumn::make('period_start')
                    ->label(__('Period'))
                    ->formatStateUsing(fn (SubscriptionInvoice $record) => $record->period_start->timezone($this->timezone())->format('j M Y').' – '.$record->period_end->timezone($this->timezone())->format('j M Y')),
                TextColumn::make('amount')->label(__('Amount'))->alignEnd()->formatStateUsing(fn (Money $state) => $state->format()),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (InvoiceStatus $state) => $state->label())
                    ->color(fn (InvoiceStatus $state) => $state === InvoiceStatus::Paid ? 'success' : 'warning'),
                TextColumn::make('paid_at')->label(__('Paid'))->date('j M Y')->placeholder('—'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        $live = fn (): bool => $this->canManage()
            && in_array($this->subscription?->status, [SubscriptionStatus::Trialing, SubscriptionStatus::Active], true);

        return [
            Action::make('resumePlan')
                ->label(__('Keep my plan'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn () => $live() && $this->subscription?->cancelled_at !== null)
                ->action(function (CancelSubscription $cancel): void {
                    if ($this->attempt(fn () => $cancel->handle($this->user(), $this->company(), cancel: false))) {
                        $this->refreshData();
                        $this->toast(__('Your plan will renew as usual.'));
                    }
                }),
            Action::make('cancelPlan')
                ->label(__('Cancel plan'))
                ->color('danger')
                ->outlined()
                ->visible(fn () => $live() && $this->subscription?->cancelled_at === null)
                ->requiresConfirmation()
                ->modalHeading(__('Cancel your plan?'))
                ->modalDescription(fn () => __('It will not renew. You can keep selling until :date, and you can change your mind until then.', [
                    'date' => $this->subscription?->current_period_end?->timezone($this->timezone())->format('j M Y') ?? '—',
                ]))
                ->modalSubmitActionLabel(__('Cancel plan'))
                ->modalCancelActionLabel(__('Keep it'))
                ->action(function (CancelSubscription $cancel): void {
                    if ($this->attempt(fn () => $cancel->handle($this->user(), $this->company()))) {
                        $this->refreshData();
                        $this->toast(__('Your plan will not renew.'));
                    }
                }),
        ];
    }

    public function selectPlanAction(): Action
    {
        return Action::make('selectPlan')
            ->label(fn (array $arguments) => $this->selectLabel($this->planFrom($arguments)))
            ->color(fn (array $arguments) => $this->isCurrent($this->planFrom($arguments)) ? 'gray' : 'primary')
            ->extraAttributes(['class' => 'w-full'])
            ->visible(fn () => $this->canManage())
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedCreditCard)
            ->modalIconColor('primary')
            ->modalHeading(fn (array $arguments) => $this->selectLabel($this->planFrom($arguments)))
            ->modalDescription(fn (array $arguments) => $this->selectExplanation($this->planFrom($arguments)))
            ->modalSubmitActionLabel(__('Confirm'))
            ->action(function (array $arguments, SubscribeToPlan $subscribe, ChangePlan $change): void {
                $plan = $this->planFrom($arguments);

                $this->attempt(function () use ($plan, $subscribe, $change): void {
                    if ($this->choosing()) {
                        ['invoice' => $invoice] = $subscribe->handle($this->user(), $this->company(), $plan, $this->chosenInterval());
                        $this->toast($invoice === null
                            ? __('Welcome to :plan.', ['plan' => $plan->name])
                            : __('Pay invoice :number to start :plan.', ['number' => $invoice->number, 'plan' => $plan->name]));

                        return;
                    }

                    ['result' => $result, 'invoice' => $invoice] = $change->handle($this->user(), $this->company(), $plan, $this->chosenInterval());
                    $this->toast(match ($result) {
                        'switched' => __('You are on :plan.', ['plan' => $plan->name]),
                        'upgrade_due' => __('Pay invoice :number to move to :plan.', ['number' => $invoice?->number, 'plan' => $plan->name]),
                        'scheduled' => __('Your plan changes to :plan when the current period ends.', ['plan' => $plan->name]),
                        default => __('The scheduled change was cancelled.'),
                    });
                });

                $this->refreshData();
            });
    }

    public function payFromWalletAction(): Action
    {
        return Action::make('payFromWallet')
            ->label(__('Pay from company wallet'))
            ->icon(Heroicon::OutlinedWallet)
            ->visible(fn () => $this->canManage())
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => __('Pay :amount from the company wallet?', ['amount' => $this->invoiceFrom($arguments)->amount->format()]))
            ->modalDescription(fn () => __('The wallet has :amount available.', ['amount' => $this->wallet->available()->format()]))
            ->action(function (array $arguments, PayInvoice $pay): void {
                $invoice = $this->invoiceFrom($arguments);
                Gate::forUser($this->user())->authorize('manageBilling', $this->company());

                if ($this->attempt(fn () => $pay->fromWallet($invoice))) {
                    $this->refreshData();
                    $this->toast(__('Invoice :number paid.', ['number' => $invoice->number]));
                }
            });
    }

    public function applyCouponAction(): Action
    {
        return Action::make('applyCoupon')
            ->label(__('Have a coupon?'))
            ->link()
            ->icon(Heroicon::OutlinedTicket)
            ->visible(fn () => $this->canManage())
            ->modalHeading(__('Apply a coupon'))
            ->modalWidth('sm')
            ->modalSubmitActionLabel(__('Apply'))
            ->schema([
                TextInput::make('code')->label(__('Coupon code'))->required()->maxLength(32),
            ])
            ->action(function (array $arguments, array $data, ApplyInvoiceCoupon $apply, Action $action): void {
                $invoice = $this->invoiceFrom($arguments);

                if ($this->attempt(fn () => $apply->handle($this->user(), $invoice, (string) $data['code']), $action)) {
                    $this->refreshData();
                    $this->toast(__('Coupon applied.'));
                }
            });
    }

    public function payOnlineAction(): Action
    {
        return Action::make('payOnline')
            ->label(fn (array $arguments) => ($this->gateways[(string) ($arguments['gateway'] ?? '')] ?? null)?->label() ?? __('Pay online'))
            ->color('gray')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->visible(fn () => $this->canManage())
            ->action(function (array $arguments, StartInvoicePayment $start): void {
                $invoice = $this->invoiceFrom($arguments);
                $gateway = (string) ($arguments['gateway'] ?? '');

                try {
                    $checkout = $start->handle($this->user(), $invoice, $gateway, CheckoutUrls::forGateway($gateway));
                } catch (PaymentGatewayException $e) {
                    report($e);
                    Notification::make()->danger()->title(__('This payment method is not available right now. Try again later or choose another one.'))->send();

                    return;
                }

                $this->redirect($checkout->redirectUrl);
            });
    }

    /** @param  array<string, mixed>  $arguments */
    private function planFrom(array $arguments): Plan
    {
        return $this->plans->firstWhere('id', (int) ($arguments['plan'] ?? 0)) ?? abort(404);
    }

    /** @param  array<string, mixed>  $arguments */
    private function invoiceFrom(array $arguments): SubscriptionInvoice
    {
        return $this->openInvoices->firstWhere('id', (int) ($arguments['invoice'] ?? 0)) ?? abort(404);
    }

    private function selectLabel(Plan $plan): string
    {
        return match (true) {
            $this->isCurrent($plan) => __('Keep :plan', ['plan' => $plan->name]),
            $this->choosing() && $this->trialAvailable($plan) => __('Start free trial'),
            $this->choosing() => __('Choose :plan', ['plan' => $plan->name]),
            default => __('Switch to :plan', ['plan' => $plan->name]),
        };
    }

    /** What will happen, in the words the confirmation shows. */
    private function selectExplanation(Plan $plan): string
    {
        $interval = $this->chosenInterval();
        $price = $plan->priceFor($interval)?->format() ?? '—';
        $subscription = $this->subscription;

        if ($this->choosing()) {
            return match (true) {
                $this->trialAvailable($plan) => __('Your :days-day trial starts now. After it, :plan costs :price per :interval.', ['days' => $plan->trial_days, 'plan' => $plan->name, 'price' => $price, 'interval' => $this->unit($interval)]),
                $plan->priceFor($interval)?->isZero() ?? false => __(':plan is free. It starts now.', ['plan' => $plan->name]),
                default => __('We issue an invoice for :price. Your plan starts as soon as it is paid, from the company wallet or online.', ['price' => $price]),
            };
        }

        if ($this->isCurrent($plan)) {
            return __('The scheduled change is cancelled and you stay on :plan.', ['plan' => $plan->name]);
        }

        if ($subscription?->status === SubscriptionStatus::Trialing) {
            return __('Your trial continues on :plan. After it, :plan costs :price per :interval.', ['plan' => $plan->name, 'price' => $price, 'interval' => $this->unit($interval)]);
        }

        $currentRate = $subscription === null ? 0 : intdiv($subscription->price->amount, $subscription->billing_interval->months());

        return ($plan->monthlyRate($interval) ?? 0) > $currentRate
            ? __('An upgrade: we credit the unused part of your current period and invoice the rest. :plan starts when that invoice is paid.', ['plan' => $plan->name])
            : __(':plan starts when your current period ends on :date. Nothing changes until then.', ['plan' => $plan->name, 'date' => $subscription?->current_period_end?->timezone($this->timezone())->format('j M Y') ?? '—']);
    }

    /** Computed values are cached for the request; drop them after a change. */
    private function refreshData(): void
    {
        unset($this->subscription, $this->openInvoices, $this->wallet, $this->notice);
        $this->resetTable();
    }
}
