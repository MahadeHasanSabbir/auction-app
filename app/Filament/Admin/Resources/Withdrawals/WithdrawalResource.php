<?php

namespace App\Filament\Admin\Resources\Withdrawals;

use App\Enums\PayoutMethod;
use App\Enums\WithdrawalStatus;
use App\Filament\Admin\Resources\Withdrawals\Pages\ListWithdrawals;
use App\Filament\Admin\Resources\Withdrawals\Pages\ViewWithdrawal;
use App\Models\WithdrawalRequest;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The payout desk: buyers' and companies' withdrawal requests. Staff
 * approve, pay (after sending the money outside the platform) or reject,
 * through the withdrawal actions; nobody approves their own request.
 */
class WithdrawalResource extends Resource
{
    protected static ?string $model = WithdrawalRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'withdrawal';

    public static function getNavigationGroup(): string
    {
        return __('Desk');
    }

    public static function getNavigationLabel(): string
    {
        return __('Withdrawals');
    }

    public static function getNavigationBadge(): ?string
    {
        $open = WithdrawalRequest::query()->whereIn('status', [WithdrawalStatus::Pending, WithdrawalStatus::Approved])->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return __('Waiting for review or payment');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['wallet.user:id,name,email', 'wallet.company:id,name', 'requester:id,name', 'reviewer:id,name']);
    }

    /** Who the money belongs to: the company, or the buyer. */
    public static function holder(WithdrawalRequest $record): string
    {
        return $record->wallet->company->name ?? $record->wallet->user->name ?? '—';
    }

    public static function status(TextColumn|TextEntry $component): TextColumn|TextEntry
    {
        return $component->badge()
            ->formatStateUsing(fn (WithdrawalStatus $state) => $state->label())
            ->color(fn (WithdrawalStatus $state) => match ($state) {
                WithdrawalStatus::Pending => 'warning',
                WithdrawalStatus::Approved => 'info',
                WithdrawalStatus::Paid => 'success',
                WithdrawalStatus::Rejected => 'danger',
                WithdrawalStatus::Cancelled => 'gray',
            });
    }

    public static function table(Table $table): Table
    {
        $money = fn ($state) => $state->format();

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('holder')->label(__('Wallet of'))
                    ->state(fn (WithdrawalRequest $record) => self::holder($record))
                    ->description(fn (WithdrawalRequest $record) => $record->wallet->company_id !== null ? __('Company') : __('Buyer')),
                TextColumn::make('amount')->label(__('Amount'))->formatStateUsing($money)->alignEnd(),
                TextColumn::make('net_amount')->label(__('To send'))->formatStateUsing($money)->alignEnd()->weight('semibold'),
                TextColumn::make('method')->label(__('To'))
                    ->state(fn (WithdrawalRequest $record) => $record->method->describe($record->destination)),
                self::status(TextColumn::make('status')->label(__('Status'))),
                TextColumn::make('created_at')->label(__('Requested'))->dateTime('j M Y, H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('method')->label(__('Method'))
                    ->options(collect(PayoutMethod::cases())->mapWithKeys(fn (PayoutMethod $method) => [$method->value => $method->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    WithdrawalActions::approve(),
                    WithdrawalActions::pay(),
                    WithdrawalActions::reject(),
                ]),
            ])
            ->emptyStateHeading(__('No withdrawals here'));
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn ($state) => $state?->format();
        $date = 'j M Y, H:i';

        return $schema
            ->columns(3)
            ->components([
                Section::make(__('Request'))
                    ->columnSpan(2)
                    ->columns(3)
                    ->schema([
                        TextEntry::make('holder')->label(__('Wallet of'))->state(fn (WithdrawalRequest $record) => self::holder($record)),
                        TextEntry::make('requester.name')->label(__('Asked by')),
                        self::status(TextEntry::make('status')->label(__('Status'))),
                        TextEntry::make('amount')->label(__('Amount'))->formatStateUsing($money),
                        TextEntry::make('charges')->label(__('Charges'))
                            ->state(fn (WithdrawalRequest $record) => $record->gateway_charge->plus($record->service_charge)->format()),
                        TextEntry::make('net_amount')->label(__('To send'))->formatStateUsing($money)->weight('semibold'),
                        TextEntry::make('created_at')->label(__('Requested'))->dateTime($date),
                        TextEntry::make('reviewer.name')->label(__('Reviewed by'))->placeholder('—'),
                        TextEntry::make('reviewed_at')->label(__('Reviewed'))->dateTime($date)->placeholder('—'),
                        TextEntry::make('payout_reference')->label(__('Payout reference'))->placeholder('—'),
                        TextEntry::make('paid_at')->label(__('Paid'))->dateTime($date)->placeholder('—'),
                        TextEntry::make('rejection_reason')->label(__('Reason for rejecting'))->placeholder('—')
                            ->visible(fn (WithdrawalRequest $record) => $record->status === WithdrawalStatus::Rejected),
                    ]),
                Section::make(__('Send to'))
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('method')->label(__('Method'))->formatStateUsing(fn ($state) => $state->label()),
                        TextEntry::make('destination.account_name')->label(__('Account name'))
                            ->state(fn (WithdrawalRequest $record) => $record->destination['account_name'] ?? null),
                        TextEntry::make('destination.account_number')->label(__('Account number'))->copyable()
                            ->state(fn (WithdrawalRequest $record) => $record->destination['account_number'] ?? null),
                        TextEntry::make('destination.bank')->label(__('Bank and branch'))
                            ->state(fn (WithdrawalRequest $record) => isset($record->destination['bank_name'])
                                ? $record->destination['bank_name'].', '.($record->destination['branch'] ?? '')
                                : null)
                            ->visible(fn (WithdrawalRequest $record) => ! $record->method->isMobileWallet()),
                        TextEntry::make('destination.routing_number')->label(__('Routing number'))->placeholder('—')
                            ->state(fn (WithdrawalRequest $record) => $record->destination['routing_number'] ?? null)
                            ->visible(fn (WithdrawalRequest $record) => ! $record->method->isMobileWallet()),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWithdrawals::route('/'),
            'view' => ViewWithdrawal::route('/{record}'),
        ];
    }
}
