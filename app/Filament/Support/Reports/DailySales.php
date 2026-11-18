<?php

namespace App\Filament\Support\Reports;

use App\Models\Order;
use App\Support\Money;
use App\Support\Period;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** Net sales (price less refunds) per day, for the dashboard charts. */
final class DailySales
{
    /**
     * @param  Builder<Order>  $orders
     * @return array{labels: list<string>, values: list<float>}
     */
    public static function series(Builder $orders, int $days = 30): array
    {
        $zone = (string) config('auction.display_timezone');
        $today = CarbonImmutable::now($zone);

        // A fixed offset, so MySQL needs no time-zone tables.
        $totals = Period::lastDays($days)->apply($orders->toBase(), 'orders.created_at')
            ->selectRaw("date(convert_tz(orders.created_at, '+00:00', ?)) as day, sum(orders.amount - orders.refunded_amount) as total", [$today->format('P')])
            ->groupBy('day')
            ->pluck('total', 'day');

        $labels = [];
        $values = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = $today->subDays($i);
            $labels[] = $day->format('j M');
            $values[] = (float) Money::minor((int) ($totals[$day->toDateString()] ?? 0))->toDecimal();
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * The chart data Filament's line chart expects.
     *
     * @param  Builder<Order>  $orders
     * @return array<string, mixed>
     */
    public static function chart(Builder $orders, int $days = 30): array
    {
        $series = self::series($orders, $days);

        return [
            'datasets' => [[
                'label' => __('Net sales (:currency)', ['currency' => config('auction.currency')]),
                'data' => $series['values'],
                'fill' => 'start',
                'tension' => 0.3,
            ]],
            'labels' => $series['labels'],
        ];
    }
}
