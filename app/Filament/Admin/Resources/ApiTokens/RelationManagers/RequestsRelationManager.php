<?php

namespace App\Filament\Admin\Resources\ApiTokens\RelationManagers;

use App\Filament\Admin\Resources\ApiRequests\ApiRequestResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/** The requests made with one token, newest first. */
class RequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'requests';

    protected static ?string $title = 'Requests';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns(ApiRequestResource::columns(withUser: false))
            ->filters(ApiRequestResource::filters())
            ->emptyStateHeading(__('No requests recorded for this token'));
    }
}
