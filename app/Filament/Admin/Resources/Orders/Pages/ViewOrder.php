<?php

namespace App\Filament\Admin\Resources\Orders\Pages;

use App\Filament\Admin\Resources\Orders\OrderActions;
use App\Filament\Admin\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['shipment', 'events', 'latestDispute']);
    }

    public function getTitle(): string
    {
        return __('Order :number', ['number' => $this->getRecord()->getAttribute('number')]);
    }

    protected function getHeaderActions(): array
    {
        return [OrderActions::resolve()];
    }
}
