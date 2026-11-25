<?php

namespace App\Filament\Seller\Resources\Auctions\Pages;

use App\Filament\Seller\Resources\Auctions\AuctionResource;
use App\Filament\Seller\Resources\Auctions\Pages\Concerns\SavesAuction;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAuction extends CreateRecord
{
    use SavesAuction;

    protected static string $resource = AuctionResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return __('New auction');
    }

    protected function handleRecordCreation(array $data): Model
    {
        return $this->saveAuction(null, $data);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('Draft saved. Send it for review when it is ready.');
    }

    protected function getRedirectUrl(): string
    {
        return AuctionResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
