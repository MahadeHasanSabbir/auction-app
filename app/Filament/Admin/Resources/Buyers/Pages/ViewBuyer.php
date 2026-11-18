<?php

namespace App\Filament\Admin\Resources\Buyers\Pages;

use App\Filament\Admin\Resources\AccountActions;
use App\Filament\Admin\Resources\Buyers\BuyerResource;
use Filament\Resources\Pages\ViewRecord;

class ViewBuyer extends ViewRecord
{
    protected static string $resource = BuyerResource::class;

    public function getTitle(): string
    {
        return (string) $this->getRecord()->getAttribute('name');
    }

    protected function getHeaderActions(): array
    {
        return [AccountActions::suspend(), AccountActions::reactivate()];
    }
}
