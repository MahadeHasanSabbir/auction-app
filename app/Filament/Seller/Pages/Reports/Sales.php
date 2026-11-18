<?php

namespace App\Filament\Seller\Pages\Reports;

use App\Filament\Support\Reports\SalesReportPage;
use App\Models\Company;
use App\Models\Order;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

/** The company's own orders in a period; the whole team may look. */
class Sales extends SalesReportPage
{
    protected static ?int $navigationSort = 40;

    protected function forPlatform(): bool
    {
        return false;
    }

    protected function orders(): Builder
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return Order::query()->where('orders.company_id', $company->id);
    }
}
