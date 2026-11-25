<?php

namespace App\Filament\Seller\Resources\Auctions\Pages;

use App\Enums\AuctionStatus;
use App\Filament\Seller\Resources\Auctions\AuctionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAuctions extends ListRecords
{
    protected static string $resource = AuctionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New auction'))];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tab = fn (string $label, array $statuses) => Tab::make($label)
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', $statuses));

        return [
            'all' => Tab::make(__('All')),
            'drafts' => $tab(__('Drafts'), [AuctionStatus::Draft, AuctionStatus::Rejected]),
            'review' => $tab(__('In review'), [AuctionStatus::PendingReview]),
            'scheduled' => $tab(__('Scheduled'), [AuctionStatus::Scheduled]),
            'live' => $tab(__('Live'), [AuctionStatus::Live]),
            'finished' => $tab(__('Finished'), [AuctionStatus::Sold, AuctionStatus::Unsold, AuctionStatus::Cancelled]),
        ];
    }
}
