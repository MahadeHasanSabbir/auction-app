<?php

namespace App\Filament\Admin\Resources\Withdrawals\Pages;

use App\Filament\Admin\Resources\Withdrawals\WithdrawalActions;
use App\Filament\Admin\Resources\Withdrawals\WithdrawalResource;
use Filament\Resources\Pages\ViewRecord;

class ViewWithdrawal extends ViewRecord
{
    protected static string $resource = WithdrawalResource::class;

    public function getTitle(): string
    {
        return __('Withdrawal #:id', ['id' => $this->getRecord()->getKey()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            WithdrawalActions::approve(),
            WithdrawalActions::pay(),
            WithdrawalActions::reject(),
        ];
    }
}
