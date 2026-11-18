<?php

namespace App\Filament\Admin\Resources\Withdrawals\Pages;

use App\Enums\WithdrawalStatus;
use App\Filament\Admin\Resources\Withdrawals\WithdrawalResource;
use App\Models\WithdrawalRequest;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListWithdrawals extends ListRecords
{
    protected static string $resource = WithdrawalResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tab = fn (string $label, WithdrawalStatus ...$statuses) => Tab::make($label)
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', $statuses));
        $count = fn (WithdrawalStatus $status) => fn () => WithdrawalRequest::query()->where('status', $status)->count() ?: null;

        return [
            'pending' => $tab(__('To review'), WithdrawalStatus::Pending)->badge($count(WithdrawalStatus::Pending))->badgeColor('warning'),
            'approved' => $tab(__('To pay'), WithdrawalStatus::Approved)->badge($count(WithdrawalStatus::Approved))->badgeColor('info'),
            'paid' => $tab(__('Paid'), WithdrawalStatus::Paid),
            'closed' => $tab(__('Rejected or cancelled'), WithdrawalStatus::Rejected, WithdrawalStatus::Cancelled),
            'all' => Tab::make(__('All')),
        ];
    }
}
