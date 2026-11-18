<?php

namespace App\Filament\Admin\Resources\Orders;

use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\Orders\Pages\ListOrders;
use App\Filament\Admin\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every order on the marketplace, for platform staff: follow delivery, read
 * the buyer–seller chat and decide disputes (OrderActions::resolve). Buyers
 * and sellers change orders from their own pages.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationGroup(): string
    {
        return __('Desk');
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
        return parent::getEloquentQuery()->with(['auction:id,title', 'buyer:id,name,email', 'company:id,name']);
    }

    public static function status(TextColumn|TextEntry $component): TextColumn|TextEntry
    {
        return $component->badge()
            ->formatStateUsing(fn (OrderStatus $state) => $state->label())
            ->color(fn (OrderStatus $state) => $state->color());
    }

    public static function table(Table $table): Table
    {
        $money = fn ($state) => $state->format();

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Order'))->searchable()->weight('medium')
                    ->description(fn (Order $record) => $record->auction?->title),
                TextColumn::make('buyer.name')->label(__('Buyer'))->searchable(),
                TextColumn::make('company.name')->label(__('Seller'))->searchable(),
                self::status(TextColumn::make('status')->label(__('Status'))),
                TextColumn::make('amount')->label(__('Price'))->formatStateUsing($money)->alignEnd(),
                TextColumn::make('commission')->label(__('Commission'))->formatStateUsing($money)->alignEnd()->toggleable(),
                TextColumn::make('created_at')->label(__('Ordered'))->dateTime('j M Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('company_id')->label(__('Seller'))->relationship('company', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([OrderActions::resolve()]),
            ])
            ->emptyStateHeading(__('No orders here'));
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn ($state) => $state?->format();
        $date = 'j M Y, H:i';

        return $schema
            ->columns(3)
            ->components([
                Section::make(__('Dispute'))
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->iconColor('danger')
                    ->columnSpanFull()
                    ->columns(3)
                    ->visible(fn (Order $record) => $record->latestDispute !== null)
                    ->schema([
                        TextEntry::make('latestDispute.reason')->label(__('Reason'))->formatStateUsing(fn ($state) => $state?->label()),
                        TextEntry::make('latestDispute.status')->label(__('Status'))->badge()->formatStateUsing(fn ($state) => $state?->label()),
                        TextEntry::make('latestDispute.created_at')->label(__('Opened'))->dateTime($date),
                        TextEntry::make('latestDispute.description')->label(__('What the buyer says'))->columnSpanFull(),
                        TextEntry::make('latestDispute.resolution')->label(__('Decision'))->formatStateUsing(fn ($state) => $state?->label())->placeholder(__('Not decided')),
                        TextEntry::make('latestDispute.refund_amount')->label(__('Refunded'))->formatStateUsing($money)->placeholder('—'),
                        TextEntry::make('latestDispute.resolution_note')->label(__('Note'))->placeholder('—'),
                    ]),
                Section::make(__('Order'))
                    ->columnSpan(2)
                    ->columns(3)
                    ->schema([
                        TextEntry::make('auction.title')->label(__('Item'))->columnSpan(2),
                        self::status(TextEntry::make('status')->label(__('Status'))),
                        TextEntry::make('buyer.name')->label(__('Buyer'))->helperText(fn (Order $record) => $record->buyer?->email),
                        TextEntry::make('company.name')->label(__('Seller')),
                        TextEntry::make('created_at')->label(__('Ordered'))->dateTime($date),
                        TextEntry::make('amount')->label(__('Price'))->formatStateUsing($money),
                        TextEntry::make('commission')->label(__('Commission'))->formatStateUsing($money),
                        TextEntry::make('seller_payout')->label(__('Seller payout'))->formatStateUsing($money),
                        TextEntry::make('refunded_amount')->label(__('Refunded'))->formatStateUsing($money),
                        TextEntry::make('ship_by_at')->label(__('Ship by'))->dateTime($date)->placeholder('—'),
                        TextEntry::make('auto_release_at')->label(__('Payout on'))->dateTime($date)->placeholder('—'),
                    ]),
                Section::make(__('Delivery'))
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('shipping_address')->label(__('Address'))->placeholder(__('Not given yet')),
                        TextEntry::make('shipment.courier')->label(__('Courier'))->placeholder('—'),
                        TextEntry::make('shipment.tracking_number')->label(__('Tracking number'))->placeholder('—')
                            ->url(fn (Order $record) => $record->shipment?->tracking_url, shouldOpenInNewTab: true),
                        TextEntry::make('shipped_at')->label(__('Shipped'))->dateTime($date)->placeholder('—'),
                    ]),
                Section::make(__('Messages between buyer and seller'))
                    ->columnSpan(2)
                    ->schema([View::make('filament.admin.order-messages')]),
                Section::make(__('History'))
                    ->columnSpan(1)
                    ->schema([
                        RepeatableEntry::make('events')
                            ->hiddenLabel()
                            ->contained(false)
                            ->schema([
                                TextEntry::make('created_at')->hiddenLabel()->dateTime($date)->color('gray')->size('sm'),
                                TextEntry::make('description')->hiddenLabel(),
                            ]),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
