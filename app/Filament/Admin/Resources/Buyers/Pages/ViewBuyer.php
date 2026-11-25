<?php

namespace App\Filament\Admin\Resources\Buyers\Pages;

use App\Filament\Admin\Resources\AccountActions;
use App\Filament\Admin\Resources\Buyers\BuyerResource;
use App\Filament\Admin\Resources\Kyc\KycActions;
use App\Filament\Admin\Resources\TrustActions;
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
        return [KycActions::request(), TrustActions::strike(), TrustActions::fine(), AccountActions::suspend(), AccountActions::reactivate(), AccountActions::anonymise()];
    }
}
