<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Filament\Support\Reports\AuctionResultsReportPage;
use App\Models\Auction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/** Every company's auctions that ended in a period. */
class AuctionResults extends AuctionResultsReportPage
{
    protected static ?int $navigationSort = 43;

    public static function canAccess(): bool
    {
        return Gate::allows('viewReports');
    }

    protected function forPlatform(): bool
    {
        return true;
    }

    protected function auctions(): Builder
    {
        return Auction::query();
    }
}
