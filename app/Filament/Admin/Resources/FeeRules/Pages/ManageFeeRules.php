<?php

namespace App\Filament\Admin\Resources\FeeRules\Pages;

use App\Filament\Admin\Resources\FeeRules\FeeRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Auth;

class ManageFeeRules extends ManageRecords
{
    protected static string $resource = FeeRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('Add rule'))
                ->mutateDataUsing(fn (array $data) => [...$data, 'created_by' => Auth::id()]),
        ];
    }
}
