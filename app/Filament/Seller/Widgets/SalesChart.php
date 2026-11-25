<?php

namespace App\Filament\Seller\Widgets;

use App\Filament\Support\Reports\DailySales;
use App\Models\Company;
use App\Models\Order;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/** The company's net sales per day over the last 30 days. */
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
        /** @var Company $company */
        $company = Filament::getTenant();

        return DailySales::chart(Order::query()->where('orders.company_id', $company->id));
    }
}
