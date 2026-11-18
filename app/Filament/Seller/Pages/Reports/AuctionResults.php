<?php

namespace App\Filament\Seller\Pages\Reports;

use App\Filament\Support\Reports\AuctionResultsReportPage;
use App\Models\Auction;
use App\Models\Company;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

/** The company's own auctions that ended in a period. */
class AuctionResults extends AuctionResultsReportPage
{
    protected static ?int $navigationSort = 41;

    protected function forPlatform(): bool
    {
        return false;
    }

    protected function auctions(): Builder
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return Auction::query()->where('auctions.company_id', $company->id);
    }
}
