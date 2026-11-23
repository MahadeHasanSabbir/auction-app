<?php

namespace App\Filament\Admin\Resources\ApiTokens\Pages;

use App\Filament\Admin\Resources\ApiTokens\ApiTokenResource;
use Filament\Resources\Pages\ViewRecord;

class ViewApiToken extends ViewRecord
{
    protected static string $resource = ApiTokenResource::class;

    public function getTitle(): string
    {
        return __('API token: :name', ['name' => $this->getRecord()->getAttribute('name')]);
    }

    protected function getHeaderActions(): array
    {
        return [ApiTokenResource::revokeAction()->after(fn () => $this->redirect(ApiTokenResource::getUrl()))];
    }
}
