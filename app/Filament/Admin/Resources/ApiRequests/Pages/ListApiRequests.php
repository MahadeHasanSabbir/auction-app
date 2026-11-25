<?php

namespace App\Filament\Admin\Resources\ApiRequests\Pages;

use App\Filament\Admin\Resources\ApiRequests\ApiRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListApiRequests extends ListRecords
{
    protected static string $resource = ApiRequestResource::class;
}
