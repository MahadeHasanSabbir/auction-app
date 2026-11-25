<?php

namespace App\Filament\Admin\Resources\Kyc\Pages;

use App\Enums\KycStatus;
use App\Filament\Admin\Resources\Kyc\KycRequestResource;
use App\Models\KycRequest;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListKycRequests extends ListRecords
{
    protected static string $resource = KycRequestResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tab = fn (string $label, KycStatus $status) => Tab::make($label)
            ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status));

        return [
            'submitted' => $tab(__('To check'), KycStatus::Submitted)
                ->badge(fn () => KycRequest::query()->where('status', KycStatus::Submitted)->count() ?: null)->badgeColor('warning'),
            'requested' => $tab(__('Waiting for documents'), KycStatus::Requested),
            'approved' => $tab(__('Approved'), KycStatus::Approved),
            'rejected' => $tab(__('Rejected'), KycStatus::Rejected),
            'all' => Tab::make(__('All')),
        ];
    }
}
