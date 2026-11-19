<?php

namespace App\Filament\Admin\Resources\Blacklist\Pages;

use App\Filament\Admin\Resources\Blacklist\BlacklistEntryResource;
use App\Models\BlacklistEntry;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Auth;

class ManageBlacklistEntries extends ManageRecords
{
    protected static string $resource = BlacklistEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Block'))->using(function (array $data): BlacklistEntry {
                $entry = new BlacklistEntry($data);
                $entry->created_by = (int) Auth::id();
                $entry->save();

                activity('admin')->causedBy(Auth::user())->performedOn($entry)->event('blocked')
                    ->withProperties(['type' => $entry->type->value, 'value' => $entry->value, 'reason' => $entry->reason])
                    ->log("blocked {$entry->type->value} {$entry->value}");

                return $entry;
            }),
        ];
    }
}
