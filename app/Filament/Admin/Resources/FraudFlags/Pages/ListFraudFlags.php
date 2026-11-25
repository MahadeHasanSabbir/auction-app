<?php

namespace App\Filament\Admin\Resources\FraudFlags\Pages;

use App\Enums\FraudFlagStatus;
use App\Filament\Admin\Resources\FraudFlags\FraudFlagResource;
use App\Models\FraudFlag;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListFraudFlags extends ListRecords
{
    protected static string $resource = FraudFlagResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tab = fn (FraudFlagStatus $status) => Tab::make($status->label())
            ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status));

        return [
            'open' => $tab(FraudFlagStatus::Open)
                ->badge(fn () => FraudFlag::query()->where('status', FraudFlagStatus::Open)->count() ?: null)->badgeColor('danger'),
            'confirmed' => $tab(FraudFlagStatus::Confirmed),
            'dismissed' => $tab(FraudFlagStatus::Dismissed),
            'all' => Tab::make(__('All')),
        ];
    }
}
