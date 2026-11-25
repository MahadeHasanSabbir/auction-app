<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\AuctionStatus;
use App\Enums\OrderStatus;
use App\Enums\SystemAccount;
use App\Filament\Admin\Pages\Reports\Revenue;
use App\Filament\Admin\Pages\Reports\Sales;
use App\Filament\Admin\Resources\Disputes\DisputeResource;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Filament\Admin\Resources\Tickets\TicketResource;
use App\Models\Auction;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Wallet;
use App\Support\Money;
use App\Support\Period;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

/** The marketplace's last 30 days and the order and support desks, under PlatformOverview. */
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
        $period = Period::lastDays(30);

        $sales = $period->apply(Order::query()->toBase(), 'created_at')
            ->selectRaw('count(*) as orders, coalesce(sum(amount - discount - refunded_amount), 0) as net')
            ->first();

        $toShip = Order::query()->where('status', OrderStatus::InEscrow)->count();
        $disputes = (int) (DisputeResource::getNavigationBadge() ?? 0);
        $tickets = (int) (TicketResource::getNavigationBadge() ?? 0);

        $stats = [
            Stat::make(__('Net sales, 30 days'), Money::minor((int) ($sales->net ?? 0))->format())
                ->description(trans_choice(':count order|:count orders', (int) ($sales->orders ?? 0)))
                ->icon(Heroicon::OutlinedPresentationChartLine)
                ->url(Sales::getUrl()),
        ];

        if (Gate::allows('viewRevenue')) {
            $commission = $period->apply(LedgerEntry::query()->toBase(), 'created_at')
                ->whereIn('wallet_id', Wallet::query()->where('system_account', SystemAccount::CommissionRevenue)->select('id'))
                ->sum('amount');

            $stats[] = Stat::make(__('Commission, 30 days'), Money::minor((int) $commission)->format())
                ->description(__('Earned on finished orders'))
                ->icon(Heroicon::OutlinedBanknotes)
                ->url(Revenue::getUrl());
        }

        return [
            ...$stats,
            Stat::make(__('Live auctions'), (string) Auction::query()->where('status', AuctionStatus::Live)->count())
                ->icon(Heroicon::OutlinedBolt),
            Stat::make(__('Orders to ship'), (string) $toShip)
                ->description(trans_choice(':count open dispute|:count open disputes', $disputes))
                ->descriptionColor($disputes > 0 ? 'danger' : 'gray')
                ->icon(Heroicon::OutlinedTruck)
                ->url($disputes > 0 ? DisputeResource::getUrl() : OrderResource::getUrl()),
            Stat::make(__('Tickets not taken'), (string) $tickets)
                ->descriptionColor($tickets > 0 ? 'warning' : 'success')
                ->description($tickets > 0 ? __('Someone is waiting') : __('All caught up'))
                ->icon(Heroicon::OutlinedLifebuoy)
                ->url(TicketResource::getUrl()),
        ];
    }
}
