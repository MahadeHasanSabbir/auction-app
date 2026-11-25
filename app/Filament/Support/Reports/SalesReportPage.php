<?php

namespace App\Filament\Support\Reports;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\Money;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Orders opened in a period with their money: price, commission, refunds and
 * what the seller keeps. The platform sees every company; a seller its own.
 * Commission and payout are final once an order is finished; open orders
 * show the expected figures.
 */
abstract class SalesReportPage extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?string $slug = 'reports/sales';

    /** Whether this is the platform's view (every company, company column). */
    abstract protected function forPlatform(): bool;

    /** @return Builder<Order> */
    abstract protected function orders(): Builder;

    public static function getNavigationLabel(): string
    {
        return __('Sales');
    }

    public function getTitle(): string
    {
        return __('Sales report');
    }

    public function getSubheading(): string
    {
        return __('Orders opened in the period. Commission and payout are final once an order is finished; open orders show the expected figures.');
    }

    public function table(Table $table): Table
    {
        $money = fn (Money $state) => $state->format();

        return $table
            ->query($this->orders()->with(['auction:id,title', 'buyer:id,name', 'company:id,name']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Date'))->dateTime('j M Y')->sortable(),
                TextColumn::make('number')->label(__('Order'))->searchable()->weight('medium')
                    ->description(fn (Order $record) => $record->auction?->title),
                TextColumn::make('company.name')->label(__('Seller'))->visible($this->forPlatform()),
                TextColumn::make('buyer.name')->label(__('Buyer'))->toggleable(),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                    ->color(fn (OrderStatus $state) => $state->color()),
                TextColumn::make('amount')->label(__('Price'))->formatStateUsing($money)->alignEnd()->sortable(),
                TextColumn::make('commission')->label(__('Commission'))->formatStateUsing($money)->alignEnd(),
                TextColumn::make('discount')->label(__('Seller discount'))->formatStateUsing($money)->alignEnd()->toggleable(),
                TextColumn::make('refunded_amount')->label(__('Refunded'))->formatStateUsing($money)->alignEnd(),
                TextColumn::make('seller_payout')->label(__('Seller payout'))->formatStateUsing($money)->alignEnd(),
            ])
            ->filters([
                self::periodFilter('orders.created_at'),
                SelectFilter::make('status')->label(__('Status'))->multiple()
                    ->options(collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $s) => [$s->value => $s->label()])),
                SelectFilter::make('company_id')->label(__('Seller'))->relationship('company', 'name')->searchable()->preload()
                    ->visible($this->forPlatform()),
            ])
            ->filtersFormColumns(2)
            ->emptyStateHeading(__('No orders in this period'));
    }

    public function summary(): array
    {
        $totals = $this->filteredQuery()->toBase()
            ->selectRaw('count(*) as orders, coalesce(sum(amount), 0) as amount, coalesce(sum(commission), 0) as commission, coalesce(sum(discount), 0) as discount, coalesce(sum(refunded_amount), 0) as refunded')
            ->first();

        $format = fn (string $key) => Money::minor((int) ($totals->{$key} ?? 0))->format();

        return [
            __('Orders') => number_format((int) ($totals->orders ?? 0)),
            __('Sales') => $format('amount'),
            __('Seller discounts') => $format('discount'),
            __('Refunded') => $format('refunded'),
            __('Commission') => $format('commission'),
        ];
    }

    protected function csvName(): string
    {
        return 'sales';
    }

    protected function csvHeader(): array
    {
        return array_values(array_filter([
            __('Date'), __('Order'), __('Item'), $this->forPlatform() ? __('Seller') : null, __('Buyer'), __('Status'),
            __('Price'), __('Buyer fee'), __('Commission'), __('Seller discount'), __('Refunded'), __('Seller payout'), __('Currency'),
        ]));
    }

    protected function csvRow(Model $record): array
    {
        /** @var Order $record */
        $row = [
            $record->created_at?->setTimezone((string) config('auction.display_timezone'))->format('Y-m-d H:i'),
            $record->number,
            $record->auction?->title,
        ];

        if ($this->forPlatform()) {
            $row[] = $record->company?->name;
        }

        return [
            ...$row,
            $record->buyer?->name,
            $record->status->label(),
            $record->amount->toDecimal(),
            $record->buyer_fee->toDecimal(),
            $record->commission->toDecimal(),
            $record->discount->toDecimal(),
            $record->refunded_amount->toDecimal(),
            $record->seller_payout->toDecimal(),
            $record->amount->currency,
        ];
    }
}
