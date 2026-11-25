<?php

namespace App\Filament\Admin\Resources\UserTypes\Pages;

use App\Filament\Admin\Resources\UserTypes\UserTypeResource;
use App\Models\UserType;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageUserTypes extends ManageRecords
{
    protected static string $resource = UserTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->after(fn (UserType $record) => UserTypeResource::keepOneDefault($record))];
    }
}
