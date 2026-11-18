<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Filament\Support\Reports\SalesReportPage;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/** Every company's orders in a period. */
class Sales extends SalesReportPage
{
    protected static ?int $navigationSort = 40;

    public static function canAccess(): bool
    {
        return Gate::allows('viewReports');
    }

    protected function forPlatform(): bool
    {
        return true;
    }

    protected function orders(): Builder
    {
        return Order::query();
    }
}
