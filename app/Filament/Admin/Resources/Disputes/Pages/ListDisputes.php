<?php

namespace App\Filament\Admin\Resources\Disputes\Pages;

use App\Enums\DisputeStatus;
use App\Filament\Admin\Resources\Disputes\DisputeResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListDisputes extends ListRecords
{
    protected static string $resource = DisputeResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tab = fn (string $label, DisputeStatus $status) => Tab::make($label)
            ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status));

        return [
            'open' => $tab(__('Open'), DisputeStatus::Open),
            'resolved' => $tab(__('Decided'), DisputeStatus::Resolved),
            'withdrawn' => $tab(__('Withdrawn'), DisputeStatus::Withdrawn),
            'all' => Tab::make(__('All')),
        ];
    }
}
