<?php

namespace App\Filament\Seller\Pages;

use App\Actions\Wallet\CancelWithdrawal;
use App\Actions\Wallet\RequestWithdrawal;
use App\Enums\PayoutMethod;
use App\Enums\WithdrawalStatus;
use App\Filament\Seller\Pages\Concerns\ForCompany;
use App\Http\Middleware\EnsureOtpConfirmed;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;

/**
 * The company wallet: what sales have earned, what is on hold, payouts and
 * the statement. Only the company owner can request a payout, and only
 * after confirming an emailed code (Livewire actions do not pass through
 * route middleware, so the action checks it).
 *
 * @property-read Wallet $wallet
 * @property-read Collection<int, WithdrawalRequest> $withdrawals
 */
class CompanyWallet extends Page implements HasTable
{
    use ForCompany;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'wallet';

    protected string $view = 'filament.seller.pages.company-wallet';

    public static function getNavigationLabel(): string
    {
        return __('Wallet & payouts');
    }

    public function getTitle(): string
    {
        return __('Wallet & payouts');
    }

    #[Computed]
    public function wallet(): Wallet
    {
        return Wallet::query()->where('company_id', $this->company()->id)->firstOrFail();
    }

    /** @return Collection<int, WithdrawalRequest> */
    #[Computed]
    public function withdrawals(): Collection
    {
        return WithdrawalRequest::query()->with('requester')->where('wallet_id', $this->wallet->id)->latest('id')->limit(10)->get();
    }

    private function otpConfirmed(): bool
    {
        return EnsureOtpConfirmed::confirmedIn(session()->driver());
    }

    public function minimum(): Money
    {
        return Money::minor((int) config('auction.payments.withdrawal_min'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('requestPayout')
                ->label(__('Request payout'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->visible(fn () => $this->canManage())
                // Without a fresh emailed code there is no form: the click goes
                // straight to the code page and comes back here.
                ->modalHidden(fn () => ! $this->otpConfirmed())
                ->modalHeading(__('Request a payout'))
                ->modalDescription(__('Staff send the money by hand, usually within two working days. Until then it stays on hold.'))
                ->modalSubmitActionLabel(__('Request payout'))
                ->modalWidth('lg')
                ->fillForm(fn () => ['method' => PayoutMethod::Bank->value, 'account_name' => $this->company()->legal_name ?? $this->company()->name])
                ->schema(fn () => ! $this->otpConfirmed() ? null : [
                    TextInput::make('amount')
                        ->label(__('Amount'))
                        ->suffix($this->wallet->currency)
                        ->inputMode('decimal')
                        ->required()
                        ->live(debounce: 400)
                        ->helperText(fn () => __('Between :min and :max (available now).', ['min' => $this->minimum()->format(), 'max' => $this->wallet->available()->format()])),
                    Select::make('method')
                        ->label(__('Send it to'))
                        ->options(collect(PayoutMethod::cases())->mapWithKeys(fn (PayoutMethod $method) => [$method->value => $method->label()])->all())
                        ->required()
                        ->native(false)
                        ->live(),
                    TextInput::make('account_name')->label(__('Account holder'))->required()->maxLength(255),
                    TextInput::make('account_number')
                        ->label(fn (Get $get) => $get('method') === PayoutMethod::Bank->value ? __('Account number') : __('Mobile number'))
                        ->placeholder(fn (Get $get) => $get('method') === PayoutMethod::Bank->value ? null : '01712345678')
                        ->required(),
                    Grid::make(2)
                        ->visible(fn (Get $get) => $get('method') === PayoutMethod::Bank->value)
                        ->schema([
                            TextInput::make('bank_name')->label(__('Bank'))->required(),
                            TextInput::make('branch')->label(__('Branch'))->required(),
                            TextInput::make('routing_number')->label(__('Routing number'))->helperText(__('Optional, nine digits.'))->columnSpanFull(),
                        ]),
                    Text::make(fn (Get $get) => $this->quoteLine((string) $get('amount'), (string) $get('method'))),
                ])
                ->action(function (array $data, Action $action, RequestWithdrawal $request): void {
                    if (! $this->otpConfirmed()) {
                        session()->put('url.intended', static::getUrl());
                        $this->redirectRoute('otp.confirm');

                        return;
                    }

                    $method = PayoutMethod::from((string) $data['method']);
                    $amount = $this->parse((string) $data['amount']);

                    if ($amount === null) {
                        Notification::make()->danger()->title(__('Enter an amount like 1500 or 1500.50.'))->send();
                        $action->halt();
                    }

                    $done = $this->attempt(fn () => $request->handle($this->user(), $this->wallet, $amount, $method, [
                        'account_name' => trim((string) $data['account_name']),
                        'account_number' => preg_replace('/[\s-]+/', '', (string) $data['account_number']),
                        'bank_name' => $method === PayoutMethod::Bank ? trim((string) ($data['bank_name'] ?? '')) : null,
                        'branch' => $method === PayoutMethod::Bank ? trim((string) ($data['branch'] ?? '')) : null,
                        'routing_number' => $method === PayoutMethod::Bank ? (trim((string) ($data['routing_number'] ?? '')) ?: null) : null,
                    ]), $action);

                    if ($done) {
                        unset($this->wallet, $this->withdrawals);
                        $this->resetTable();
                        $this->toast(__('Payout of :amount requested. The money is on hold until staff send it.', ['amount' => $amount->format()]));
                    }
                }),
        ];
    }

    public function cancelPayoutAction(): Action
    {
        return Action::make('cancelPayout')
            ->label(__('Cancel'))
            ->color('gray')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading(__('Cancel this payout request?'))
            ->modalDescription(__('The money becomes available again.'))
            ->action(function (array $arguments, CancelWithdrawal $cancel): void {
                $withdrawal = WithdrawalRequest::query()->where('wallet_id', $this->wallet->id)->findOrFail($arguments['withdrawal'] ?? 0);

                if ($this->attempt(fn () => $cancel->handle($this->user(), $withdrawal))) {
                    unset($this->wallet, $this->withdrawals);
                    $this->resetTable();
                    $this->toast(__('Payout cancelled. The money is available again.'));
                }
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(LedgerEntry::query()->with('transaction')->where('wallet_id', $this->wallet->id))
            ->heading(__('Statement'))
            ->defaultSort('id', 'desc')
            ->paginated([15, 50])
            ->emptyStateIcon(Heroicon::OutlinedBanknotes)
            ->emptyStateHeading(__('No movements yet'))
            ->emptyStateDescription(__('Sale proceeds, plan payments and payouts are listed here.'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('Date'))
                    ->state(fn (LedgerEntry $record) => $record->transaction->created_at)
                    ->dateTime('j M Y, H:i', $this->timezone()),
                TextColumn::make('transaction.type')
                    ->label(__('Description'))
                    ->formatStateUsing(fn ($state) => $state->label())
                    ->description(fn (LedgerEntry $record) => $record->transaction->description)
                    ->wrap(),
                TextColumn::make('amount')
                    ->label(__('Amount'))
                    ->alignEnd()
                    ->weight('semibold')
                    ->color(fn (Money $state) => $state->isPositive() ? 'success' : null)
                    ->formatStateUsing(fn (Money $state) => ($state->isPositive() ? '+' : '').$state->format()),
                TextColumn::make('balance_after')
                    ->label(__('Balance'))
                    ->alignEnd()
                    ->color('gray')
                    ->formatStateUsing(fn (Money $state) => $state->format()),
            ]);
    }

    public function statusColor(WithdrawalStatus $status): string
    {
        return match ($status) {
            WithdrawalStatus::Paid => 'success',
            WithdrawalStatus::Rejected => 'danger',
            WithdrawalStatus::Cancelled => 'gray',
            default => 'warning',
        };
    }

    /** "You receive …" under the payout form, once the amount can be read. */
    private function quoteLine(string $input, string $methodValue): string
    {
        $amount = $this->parse($input);
        $method = PayoutMethod::tryFrom($methodValue);

        if ($amount === null || ! $amount->isPositive() || $method === null) {
            return '';
        }

        $quote = app(RequestWithdrawal::class)->quote($this->wallet, $amount, $method);
        $charges = $quote['gateway']->amount->plus($quote['service']->amount);

        return __('Charges :charges · you receive :net', ['charges' => $charges->format(), 'net' => $quote['net']->format()]);
    }

    private function parse(string $input): ?Money
    {
        try {
            return trim($input) === '' ? null : Money::parse(trim($input));
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
