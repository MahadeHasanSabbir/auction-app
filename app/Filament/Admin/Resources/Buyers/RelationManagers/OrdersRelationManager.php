<?php

namespace App\Filament\Admin\Resources\Buyers\RelationManagers;

use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** The buyer's orders; each opens in the order desk. */
class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['auction:id,title', 'company:id,name']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Order'))->weight('medium')
                    ->description(fn (Order $record) => $record->auction?->title),
                TextColumn::make('company.name')->label(__('Seller')),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                    ->color(fn (OrderStatus $state) => $state->color()),
                TextColumn::make('amount')->label(__('Price'))->formatStateUsing(fn ($state) => $state->format())->alignEnd(),
                TextColumn::make('created_at')->label(__('Ordered'))->date('j M Y'),
            ])
            ->recordUrl(fn (Order $record) => OrderResource::getUrl('view', ['record' => $record]));
    }
}
