<?php

namespace App\Filament\Admin\Resources\Deposits\Pages;

use App\Filament\Admin\Resources\Deposits\DepositResource;
use Filament\Resources\Pages\ViewRecord;

class ViewDeposit extends ViewRecord
{
    protected static string $resource = DepositResource::class;

    public function getTitle(): string
    {
        return __('Deposit :id', ['id' => $this->getRecord()->getAttribute('tran_id')]);
    }
}
