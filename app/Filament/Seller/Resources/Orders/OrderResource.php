<?php

namespace App\Filament\Seller\Resources\Orders;

use App\Enums\OrderStatus;
use App\Filament\Seller\Resources\Orders\Pages\ListOrders;
use App\Filament\Seller\Resources\Orders\Pages\ViewOrder;
use App\Models\Company;
use App\Models\Order;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The company's orders: ship them, follow delivery and disputes, see the
 * payout and answer the buyer's review. Every change goes through the
 * actions in OrderActions.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'number';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['auction:id,title,product_id', 'auction.product:id', 'auction.product.coverImage']);
    }

    public static function getNavigationBadge(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return null;
        }

        $toShip = Order::query()->where('company_id', $tenant->id)->where('status', OrderStatus::InEscrow)->whereNotNull('shipping_address')->count();

        return $toShip > 0 ? (string) $toShip : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return __('Orders to ship');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                ImageColumn::make('auction.product.coverImage.thumbnail_path')
                    ->label('')
                    ->disk('public')
                    ->imageSize(48)
                    ->square(),
                TextColumn::make('number')
                    ->label(__('Order'))
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (Order $record) => $record->auction?->title),
                TextColumn::make('shipping_name')
                    ->label(__('Buyer'))
                    ->placeholder(__('No address yet')),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                    ->color(fn (OrderStatus $state) => $state->color()),
                TextColumn::make('amount')
                    ->label(__('Price'))
                    ->formatStateUsing(fn ($state) => $state->format())
                    ->alignEnd(),
                TextColumn::make('seller_payout')
                    ->label(__('Your payout'))
                    ->formatStateUsing(fn ($state) => $state->format())
                    ->alignEnd(),
                TextColumn::make('deadline')
                    ->label(__('Next date'))
                    ->state(fn (Order $record) => match ($record->status) {
                        OrderStatus::InEscrow => $record->ship_by_at,
                        OrderStatus::Shipped => $record->auto_release_at,
                        default => null,
                    })
                    ->dateTime('j M Y, H:i')
                    ->description(fn (Order $record) => match ($record->status) {
                        OrderStatus::InEscrow => filled($record->shipping_address) ? __('Ship by') : __('Address due'),
                        OrderStatus::Shipped => __('Payout'),
                        default => null,
                    })
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label(__('Ordered'))
                    ->dateTime('j M Y')
                    ->sortable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    OrderActions::ship(),
                    OrderActions::cancel(),
                ]),
            ])
            ->emptyStateHeading(__('No orders here'))
            ->emptyStateDescription(__('When one of your auctions sells, its order appears here.'));
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn ($state) => $state?->format();
        $date = 'j M Y, H:i';

        return $schema
            ->columns(3)
            ->components([
                Section::make(__('Cancelled'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->iconColor('danger')
                    ->columnSpanFull()
                    ->visible(fn (Order $record) => $record->status === OrderStatus::Cancelled)
                    ->schema([TextEntry::make('cancellation_reason')->hiddenLabel()]),
                Section::make(__('Dispute'))
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->iconColor('danger')
                    ->columnSpanFull()
                    ->columns(2)
                    ->visible(fn (Order $record) => $record->latestDispute !== null)
                    ->schema([
                        TextEntry::make('latestDispute.reason')->label(__('Reason'))->formatStateUsing(fn ($state) => $state?->label()),
                        TextEntry::make('latestDispute.status')->label(__('Status'))->badge()->formatStateUsing(fn ($state) => $state?->label()),
                        TextEntry::make('latestDispute.description')->label(__('What the buyer says'))->columnSpanFull(),
                        TextEntry::make('latestDispute.resolution')->label(__('Decision'))->formatStateUsing(fn ($state) => $state?->label())->placeholder(__('Waiting for the platform')),
                        TextEntry::make('latestDispute.resolution_note')->label(__('Note from the platform'))->placeholder('—'),
                    ]),
                Section::make(__('Order'))
                    ->columnSpan(2)
                    ->columns(3)
                    ->schema([
                        TextEntry::make('auction.title')->label(__('Item'))->columnSpan(2),
                        TextEntry::make('status')->label(__('Status'))->badge()
                            ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                            ->color(fn (OrderStatus $state) => $state->color()),
                        TextEntry::make('amount')->label(__('Price'))->formatStateUsing($money),
                        TextEntry::make('commission')->label(__('Commission'))->formatStateUsing($money),
                        TextEntry::make('seller_payout')->label(__('Your payout'))->formatStateUsing($money)->weight('semibold'),
                        TextEntry::make('refunded_amount')->label(__('Refunded to the buyer'))->formatStateUsing($money)
                            ->visible(fn (Order $record) => $record->refunded_amount->isPositive()),
                        TextEntry::make('created_at')->label(__('Ordered'))->dateTime($date),
                        TextEntry::make('ship_by_at')->label(__('Ship by'))->dateTime($date)->placeholder('—')
                            ->visible(fn (Order $record) => $record->status === OrderStatus::InEscrow),
                        TextEntry::make('shipped_at')->label(__('Shipped'))->dateTime($date)->placeholder('—'),
                        TextEntry::make('auto_release_at')->label(__('Payout on'))->dateTime($date)->placeholder('—')
                            ->helperText(__('Unless the buyer confirms delivery earlier or opens a dispute.'))
                            ->visible(fn (Order $record) => $record->status === OrderStatus::Shipped),
                        TextEntry::make('completed_at')->label(__('Completed'))->dateTime($date)->placeholder('—')
                            ->visible(fn (Order $record) => $record->completed_at !== null),
                    ]),
                Section::make(__('Deliver to'))
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('shipping_name')->hiddenLabel()->weight('semibold')->visible(fn (Order $record) => filled($record->shipping_address)),
                        TextEntry::make('shipping_phone')->hiddenLabel()->visible(fn (Order $record) => filled($record->shipping_address) && filled($record->shipping_phone)),
                        TextEntry::make('shipping_address')->hiddenLabel()->placeholder(__('The buyer has not given an address yet.')),
                    ]),
                Section::make(__('Shipment'))
                    ->columnSpan(2)
                    ->columns(3)
                    ->visible(fn (Order $record) => $record->shipment !== null)
                    ->schema([
                        TextEntry::make('shipment.courier')->label(__('Courier')),
                        TextEntry::make('shipment.tracking_number')->label(__('Tracking number'))->placeholder('—')
                            ->url(fn (Order $record) => $record->shipment?->tracking_url, shouldOpenInNewTab: true),
                        TextEntry::make('shipment.status')->label(__('Status'))->badge()->formatStateUsing(fn ($state) => $state?->label()),
                        TextEntry::make('shipment.creator.name')->label(__('Shipped by')),
                        TextEntry::make('shipment.delivered_at')->label(__('Delivered'))->dateTime($date)->placeholder('—'),
                        TextEntry::make('shipment.note')->label(__('Note'))->placeholder('—'),
                    ]),
                Section::make(__('Review'))
                    ->columnSpan(1)
                    ->visible(fn (Order $record) => $record->review !== null)
                    ->schema([
                        TextEntry::make('review.rating')->label(__('Rating'))->formatStateUsing(fn ($state) => str_repeat('★', (int) $state).str_repeat('☆', 5 - (int) $state))->color('warning'),
                        TextEntry::make('review.comment')->label(__('Comment'))->placeholder('—'),
                        TextEntry::make('review.reply')->label(__('Your reply'))->placeholder(__('Not answered yet')),
                    ]),
                Section::make(__('History'))
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('events')
                            ->hiddenLabel()
                            ->contained(false)
                            ->columns(4)
                            ->schema([
                                TextEntry::make('created_at')->hiddenLabel()->dateTime($date)->color('gray'),
                                TextEntry::make('description')->hiddenLabel()->columnSpan(3),
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
