<?php

namespace App\Filament\Admin\Resources\Auctions\Pages;

use App\Filament\Admin\Resources\Auctions\AuctionResource;
use App\Filament\Admin\Resources\Auctions\AuctionReviewActions;
use App\Filament\Support\AuctionDetails;
use App\Models\Auction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/** @property-read Auction $record */
class ViewAuction extends ViewRecord
{
    protected static string $resource = AuctionResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['product.images', 'product.category', 'company', 'creator', 'reviewer']);
    }

    public function getTitle(): string
    {
        return $this->record->title;
    }

    public function getSubheading(): string
    {
        return $this->record->status->label().' · '.$this->record->company->name;
    }

    public function infolist(Schema $schema): Schema
    {
        return AuctionDetails::configure($schema, forPlatform: true);
    }

    protected function getHeaderActions(): array
    {
        return [
            AuctionReviewActions::approve(),
            AuctionReviewActions::reject(),
            AuctionReviewActions::cancel(),
        ];
    }
}
