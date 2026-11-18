<?php

namespace App\Filament\Seller\Resources\Orders\Pages;

use App\Filament\Seller\Resources\Orders\OrderActions;
use App\Filament\Seller\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

/** @property-read Order $record */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['auction:id,title', 'shipment.creator', 'events', 'latestDispute', 'review']);
    }

    public function getTitle(): string
    {
        return __('Order :number', ['number' => $this->record->number]);
    }

    public function getSubheading(): string
    {
        return $this->record->status->label();
    }

    protected function getHeaderActions(): array
    {
        return [
            OrderActions::ship(),
            OrderActions::reply(),
            OrderActions::cancel(),
        ];
    }
}
