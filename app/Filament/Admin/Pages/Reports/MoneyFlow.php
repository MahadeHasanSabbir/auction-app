<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\LedgerTransactionType;
use App\Enums\SystemAccount;
use App\Enums\WithdrawalStatus;
use App\Filament\Support\Reports\LedgerReference;
use App\Filament\Support\Reports\ReportPage;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Support\Money;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Money that came in through the payment gateways (deposits and plan
 * payments, gross) and went out as payouts (withdrawals paid, net), read
 * from the gateway-clearing and payout-clearing ledger accounts.
 */
class MoneyFlow extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 42;

    protected static ?string $slug = 'reports/money';

    public static function canAccess(): bool
    {
        return Gate::allows('viewReports');
    }

    public static function getNavigationLabel(): string
    {
        return __('Money in and out');
    }

    public function getTitle(): string
    {
        return __('Money in and out');
    }

    public function getSubheading(): string
    {
        return __('Payments received through the gateways and payouts sent, in the period.');
    }

    /** @return array{in: int, out: int} wallet ids of the two clearing accounts */
    private function clearing(): array
    {
        return once(fn () => [
            'in' => Wallet::forSystem(SystemAccount::GatewayClearing)->id,
            'out' => Wallet::forSystem(SystemAccount::PayoutClearing)->id,
        ]);
    }

    /**
     * Money in is a debit of gateway clearing, money out a credit of payout clearing.
     *
     * @return array{0: Money|null, 1: Money|null}
     */
    private function inAndOut(LedgerEntry $entry): array
    {
        $amount = $entry->amount;

        return $entry->wallet_id === $this->clearing()['in']
            ? [$amount->negated(), null]
            : [null, $amount];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(LedgerEntry::query()
                ->whereIn('wallet_id', array_values($this->clearing()))
                ->with(['transaction.reference']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Date'))->dateTime('j M Y, H:i')->sortable(),
                TextColumn::make('transaction.type')->label(__('For'))
                    ->formatStateUsing(fn (LedgerTransactionType $state) => $state->label()),
                TextColumn::make('reference')->label(__('Reference'))
                    ->state(fn (LedgerEntry $record) => LedgerReference::label($record->transaction))
                    ->placeholder('—'),
                TextColumn::make('money_in')->label(__('In'))->alignEnd()->placeholder('—')
                    ->state(fn (LedgerEntry $record) => $this->inAndOut($record)[0]?->format()),
                TextColumn::make('money_out')->label(__('Out'))->alignEnd()->placeholder('—')
                    ->state(fn (LedgerEntry $record) => $this->inAndOut($record)[1]?->format()),
            ])
            ->filters([
                self::periodFilter('ledger_entries.created_at'),
                SelectFilter::make('wallet_id')->label(__('Direction'))
                    ->options([$this->clearing()['in'] => __('Money in'), $this->clearing()['out'] => __('Money out')]),
            ])
            ->filtersFormColumns(2)
            ->emptyStateHeading(__('No money moved in this period'));
    }

    public function summary(): array
    {
        $sums = $this->filteredQuery()->toBase()
            ->groupBy('wallet_id')
            ->selectRaw('wallet_id, sum(amount) as total, count(*) as entries')
            ->get()
            ->keyBy('wallet_id');

        $in = -(int) ($sums[$this->clearing()['in']]->total ?? 0);
        $out = (int) ($sums[$this->clearing()['out']]->total ?? 0);
        $waiting = (int) WithdrawalRequest::query()
            ->whereIn('status', [WithdrawalStatus::Pending, WithdrawalStatus::Approved])
            ->sum('net_amount');

        return [
            __('Money in') => Money::minor($in)->format(),
            __('Money out') => Money::minor($out)->format(),
            __('Difference') => Money::minor($in - $out)->format(),
            __('Payouts waiting now') => Money::minor($waiting)->format(),
        ];
    }

    protected function csvName(): string
    {
        return 'money-in-and-out';
    }

    protected function csvHeader(): array
    {
        return [__('Date'), __('For'), __('Reference'), __('In'), __('Out'), __('Currency')];
    }

    protected function csvRow(Model $record): array
    {
        /** @var LedgerEntry $record */
        [$in, $out] = $this->inAndOut($record);

        return [
            $record->created_at?->setTimezone((string) config('auction.display_timezone'))->format('Y-m-d H:i'),
            $record->transaction?->type->label(),
            LedgerReference::label($record->transaction),
            $in?->toDecimal(),
            $out?->toDecimal(),
            $record->amount->currency,
        ];
    }
}
