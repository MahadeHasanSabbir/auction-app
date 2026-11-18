<?php

namespace App\Filament\Admin\Resources\Deposits;

use App\Enums\DepositStatus;
use App\Filament\Admin\Resources\Deposits\Pages\ListDeposits;
use App\Filament\Admin\Resources\Deposits\Pages\ViewDeposit;
use App\Models\Deposit;
use BackedEnum;
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
 * Money buyers added through a payment gateway. Read only: a deposit is
 * completed by the gateway callback, never by hand.
 */
class DepositResource extends Resource
{
    protected static ?string $model = Deposit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): string
    {
        return __('Desk');
    }

    public static function getNavigationLabel(): string
    {
        return __('Deposits');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user:id,name,email']);
    }

    public static function status(TextColumn|TextEntry $component): TextColumn|TextEntry
    {
        return $component->badge()
            ->formatStateUsing(fn (DepositStatus $state) => $state->label())
            ->color(fn (DepositStatus $state) => match ($state) {
                DepositStatus::Pending => 'warning',
                DepositStatus::Succeeded => 'success',
                DepositStatus::Failed => 'danger',
                DepositStatus::Cancelled => 'gray',
            });
    }

    public static function table(Table $table): Table
    {
        $money = fn ($state) => $state->format();

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('tran_id')->label(__('Payment'))->searchable()->fontFamily('mono'),
                TextColumn::make('user.name')->label(__('Buyer'))->searchable()
                    ->description(fn (Deposit $record) => $record->user?->email),
                TextColumn::make('gateway')->label(__('Gateway'))->badge()->color('gray'),
                TextColumn::make('amount')->label(__('Paid'))->formatStateUsing($money)->alignEnd(),
                TextColumn::make('net_amount')->label(__('Credited'))->formatStateUsing($money)->alignEnd(),
                self::status(TextColumn::make('status')->label(__('Status'))),
                TextColumn::make('created_at')->label(__('Started'))->dateTime('j M Y, H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))
                    ->options(collect(DepositStatus::cases())->mapWithKeys(fn (DepositStatus $status) => [$status->value => $status->label()])->all()),
                SelectFilter::make('gateway')->label(__('Gateway'))
                    ->options(fn () => Deposit::query()->distinct()->orderBy('gateway')->pluck('gateway', 'gateway')->all()),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading(__('No deposits yet'));
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn ($state) => $state?->format();
        $date = 'j M Y, H:i';

        return $schema
            ->columns(3)
            ->components([
                Section::make(__('Deposit'))
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('tran_id')->label(__('Payment'))->copyable(),
                        TextEntry::make('user.name')->label(__('Buyer')),
                        self::status(TextEntry::make('status')->label(__('Status'))),
                        TextEntry::make('gateway')->label(__('Gateway')),
                        TextEntry::make('gateway_reference')->label(__('Gateway reference'))->placeholder('—')->copyable(),
                        TextEntry::make('failure_reason')->label(__('Why it failed'))->placeholder('—'),
                        TextEntry::make('amount')->label(__('Paid'))->formatStateUsing($money),
                        TextEntry::make('gateway_charge')->label(__('Gateway charge'))->formatStateUsing($money),
                        TextEntry::make('service_charge')->label(__('Service charge'))->formatStateUsing($money),
                        TextEntry::make('net_amount')->label(__('Credited to the wallet'))->formatStateUsing($money)->weight('semibold'),
                        TextEntry::make('created_at')->label(__('Started'))->dateTime($date),
                        TextEntry::make('completed_at')->label(__('Completed'))->dateTime($date)->placeholder('—'),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeposits::route('/'),
            'view' => ViewDeposit::route('/{record}'),
        ];
    }
}
