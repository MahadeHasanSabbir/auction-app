<?php

namespace App\Filament\Admin\Resources\Auctions\Pages;

use App\Enums\AuctionStatus;
use App\Filament\Admin\Resources\Auctions\AuctionResource;
use App\Models\Auction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAuctions extends ListRecords
{
    protected static string $resource = AuctionResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tab = fn (string $label, array $statuses) => Tab::make($label)
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', $statuses));

        return [
            'review' => $tab(__('Awaiting review'), [AuctionStatus::PendingReview])
                ->badge(fn () => Auction::query()->where('status', AuctionStatus::PendingReview)->count() ?: null)
                ->badgeColor('warning'),
            'scheduled' => $tab(__('Scheduled'), [AuctionStatus::Scheduled]),
            'live' => $tab(__('Live'), [AuctionStatus::Live]),
            'rejected' => $tab(__('Rejected'), [AuctionStatus::Rejected]),
            'finished' => $tab(__('Finished'), [AuctionStatus::Sold, AuctionStatus::Unsold, AuctionStatus::Cancelled]),
            'all' => Tab::make(__('All')),
        ];
    }
}
