<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Support\Reports\DailySales;
use App\Models\Order;
use Filament\Widgets\ChartWidget;

/** Net sales per day over the last 30 days, every company. */
class SalesChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

    protected ?string $pollingInterval = null;

    public function getHeading(): string
    {
        return __('Net sales, last 30 days');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        return DailySales::chart(Order::query());
    }
}
