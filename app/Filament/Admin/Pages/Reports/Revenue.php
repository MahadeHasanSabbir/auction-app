<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\LedgerTransactionType;
use App\Enums\SystemAccount;
use App\Filament\Support\Reports\LedgerReference;
use App\Filament\Support\Reports\ReportPage;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Support\Money;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * What the platform earned in a period, straight from the ledger: every
 * entry on the revenue accounts (commission, service charges, gateway
 * charges, plan payments, fines), net of VAT, and the VAT itself on its own
 * line, outside the total. A refund that reverses a charge shows negative.
 */
class Revenue extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 41;

    protected static ?string $slug = 'reports/revenue';

    /** @var list<SystemAccount> */
    private const ACCOUNTS = [
        SystemAccount::CommissionRevenue,
        SystemAccount::ServiceRevenue,
        SystemAccount::GatewayCharges,
        SystemAccount::SubscriptionRevenue,
        SystemAccount::PenaltyRevenue,
        // Not revenue: the VAT included in the fees, owed to the tax authority.
        SystemAccount::TaxPayable,
    ];

    public static function canAccess(): bool
    {
        return Gate::allows('viewRevenue');
    }

    public static function getNavigationLabel(): string
    {
        return __('Revenue');
    }

    public function getTitle(): string
    {
        return __('Revenue report');
    }

    public function getSubheading(): string
    {
        return __('Every amount credited to the platform\'s revenue accounts in the period.');
    }

    /** @return array<int, string> wallet id => account label */
    private function accounts(): array
    {
        return once(fn () => Wallet::query()
            ->whereIn('system_account', self::ACCOUNTS)
            ->get(['id', 'system_account'])
            ->sortBy(fn (Wallet $wallet) => array_search($wallet->system_account, self::ACCOUNTS, true))
            ->mapWithKeys(fn (Wallet $wallet) => [$wallet->id => (string) $wallet->system_account?->label()])
            ->all());
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(LedgerEntry::query()
                ->whereIn('wallet_id', array_keys($this->accounts()))
                ->with(['transaction.reference']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Date'))->dateTime('j M Y, H:i')->sortable(),
                TextColumn::make('wallet_id')->label(__('Account'))
                    ->formatStateUsing(fn (int $state) => $this->accounts()[$state] ?? '—'),
                TextColumn::make('transaction.type')->label(__('For'))
                    ->formatStateUsing(fn (LedgerTransactionType $state) => $state->label()),
                TextColumn::make('reference')->label(__('Reference'))
                    ->state(fn (LedgerEntry $record) => LedgerReference::label($record->transaction))
                    ->placeholder('—'),
                TextColumn::make('amount')->label(__('Amount'))->alignEnd()
                    ->formatStateUsing(fn (Money $state) => $state->format()),
            ])
            ->filters([
                self::periodFilter('ledger_entries.created_at'),
                SelectFilter::make('wallet_id')->label(__('Account'))->options($this->accounts()),
            ])
            ->filtersFormColumns(2)
            ->emptyStateHeading(__('No revenue in this period'));
    }

    public function summary(): array
    {
        $sums = $this->filteredQuery()->toBase()
            ->groupBy('wallet_id')
            ->selectRaw('wallet_id, sum(amount) as total')
            ->pluck('total', 'wallet_id');

        $summary = [];

        foreach ($this->accounts() as $id => $label) {
            $summary[$label] = Money::minor((int) ($sums[$id] ?? 0))->format();
        }

        $tax = Wallet::forSystem(SystemAccount::TaxPayable)->id;

        return [...$summary, __('Total revenue') => Money::minor((int) $sums->except([$tax])->sum())->format()];
    }

    protected function csvName(): string
    {
        return 'revenue';
    }

    protected function csvHeader(): array
    {
        return [__('Date'), __('Account'), __('For'), __('Reference'), __('Amount'), __('Currency')];
    }

    protected function csvRow(Model $record): array
    {
        /** @var LedgerEntry $record */
        return [
            $record->created_at?->setTimezone((string) config('auction.display_timezone'))->format('Y-m-d H:i'),
            $this->accounts()[$record->wallet_id] ?? null,
            $record->transaction?->type->label(),
            LedgerReference::label($record->transaction),
            $record->amount->toDecimal(),
            $record->amount->currency,
        ];
    }
}
