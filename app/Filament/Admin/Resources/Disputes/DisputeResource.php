<?php

namespace App\Filament\Admin\Resources\Disputes;

use App\Enums\DisputeStatus;
use App\Filament\Admin\Resources\Disputes\Pages\ListDisputes;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Models\Dispute;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The dispute queue. Each row opens its order, where the dispute is decided. */
class DisputeResource extends Resource
{
    protected static ?string $model = Dispute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): string
    {
        return __('Desk');
    }

    public static function getNavigationBadge(): ?string
    {
        $open = Dispute::query()->where('status', DisputeStatus::Open)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
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
        return parent::getEloquentQuery()->with(['order:id,number,amount,buyer_id,company_id', 'order.buyer:id,name', 'order.company:id,name']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('order.number')->label(__('Order'))->weight('medium')->searchable(),
                TextColumn::make('order.buyer.name')->label(__('Buyer')),
                TextColumn::make('order.company.name')->label(__('Seller')),
                TextColumn::make('reason')->label(__('Reason'))->formatStateUsing(fn ($state) => $state->label())
                    ->description(fn (Dispute $record) => str($record->description)->limit(80)->toString()),
                TextColumn::make('order.amount')->label(__('At stake'))->formatStateUsing(fn ($state) => $state->format())->alignEnd(),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (DisputeStatus $state) => $state->label())
                    ->color(fn (DisputeStatus $state) => match ($state) {
                        DisputeStatus::Open => 'danger',
                        DisputeStatus::Resolved => 'success',
                        DisputeStatus::Withdrawn => 'gray',
                    }),
                TextColumn::make('created_at')->label(__('Opened'))->dateTime('j M Y, H:i')->sortable(),
            ])
            ->recordUrl(fn (Dispute $record) => $record->order !== null ? OrderResource::getUrl('view', ['record' => $record->order]) : null)
            ->emptyStateHeading(__('No disputes here'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDisputes::route('/'),
        ];
    }
}
