<?php

namespace App\Filament\Admin\Resources\Kyc\Pages;

use App\Filament\Admin\Resources\Kyc\KycActions;
use App\Filament\Admin\Resources\Kyc\KycRequestResource;
use Filament\Resources\Pages\ViewRecord;

class ViewKycRequest extends ViewRecord
{
    protected static string $resource = KycRequestResource::class;

    public function getTitle(): string
    {
        return __('Verification #:id', ['id' => $this->getRecord()->getKey()]);
    }

    protected function getHeaderActions(): array
    {
        return [KycActions::approve(), KycActions::reject()];
    }
}
