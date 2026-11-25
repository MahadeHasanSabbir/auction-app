<?php

namespace App\Filament\Seller\Resources\Auctions\Pages;

use App\Enums\AuctionStatus;
use App\Filament\Seller\Resources\Auctions\AuctionResource;
use App\Filament\Seller\Resources\Auctions\Pages\Concerns\SavesAuction;
use App\Models\Auction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/** @property-read Auction $record */
class EditAuction extends EditRecord
{
    use SavesAuction;

    protected static string $resource = AuctionResource::class;

    public function getSubheading(): ?string
    {
        return $this->record->status === AuctionStatus::Rejected
            ? __('Saving turns it back into a draft, to send for review again.')
            : null;
    }

    /** Only the form's fields: other money columns are objects Livewire cannot hold. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return Arr::only($data, ['product_id', 'title', 'starting_price', 'reserve_price', 'buy_now_price', 'min_increment', 'proposed_starts_at', 'proposed_ends_at']);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Auction $record */
        return $this->saveAuction($record, $data);
    }

    protected function getRedirectUrl(): string
    {
        return AuctionResource::getUrl('view', ['record' => $this->record]);
    }
}
