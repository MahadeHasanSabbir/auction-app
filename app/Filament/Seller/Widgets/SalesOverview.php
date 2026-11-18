<?php

namespace App\Filament\Seller\Widgets;

use App\Filament\Seller\Pages\Reports\Sales;
use App\Filament\Seller\Resources\Orders\OrderResource;
use App\Models\Company;
use App\Models\Order;
use App\Support\Money;
use App\Support\Period;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** The company's last 30 days of sales, orders to ship and rating, under CompanyOverview. */
class SalesOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '60s';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $company = Filament::getTenant();

        if (! $company instanceof Company) {
            return [];
        }

        $sales = Period::lastDays(30)->apply(Order::query()->where('company_id', $company->id)->toBase(), 'created_at')
            ->selectRaw('count(*) as orders, coalesce(sum(amount - refunded_amount), 0) as net')
            ->first();

        $toShip = (int) (OrderResource::getNavigationBadge() ?? 0);
        $rating = $company->averageRating();

        return [
            Stat::make(__('Net sales, 30 days'), Money::minor((int) ($sales->net ?? 0))->format())
                ->description(trans_choice(':count order|:count orders', (int) ($sales->orders ?? 0)))
                ->icon(Heroicon::OutlinedPresentationChartLine)
                ->url(Sales::getUrl()),
            Stat::make(__('Orders to ship'), (string) $toShip)
                ->description($toShip > 0 ? __('Buyers are waiting') : __('All caught up'))
                ->descriptionColor($toShip > 0 ? 'warning' : 'success')
                ->icon(Heroicon::OutlinedTruck)
                ->url(OrderResource::getUrl()),
            Stat::make(__('Rating'), $rating === null ? '—' : number_format($rating, 1).' / 5')
                ->description(trans_choice(':count review|:count reviews', $company->rating_count))
                ->icon(Heroicon::OutlinedStar),
        ];
    }
}
