<?php

namespace App\Filament\Admin\Resources\Companies\Pages;

use App\Filament\Admin\Resources\Companies\Actions\CompanyActions;
use App\Filament\Admin\Resources\Companies\CompanyResource;
use App\Filament\Admin\Resources\Kyc\KycActions;
use App\Filament\Admin\Resources\TrustActions;
use Filament\Resources\Pages\ViewRecord;

class ViewCompany extends ViewRecord
{
    protected static string $resource = CompanyResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->getAttribute('name');
    }

    protected function getHeaderActions(): array
    {
        return [
            CompanyActions::verify(),
            CompanyActions::suspend(),
            CompanyActions::reinstate(),
            CompanyActions::autoApprove(),
            CompanyActions::assignPlan(),
            KycActions::request(),
            TrustActions::fine(),
        ];
    }
}
