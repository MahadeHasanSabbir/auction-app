<?php

namespace App\Filament\Admin\Resources\Kyc;

use App\Models\KycDocument;
use App\Models\KycRequest;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every verification of a buyer or a company, newest first: each round
 * (asked again by staff, or sent again after a rejection) is its own row
 * and keeps its files. A row opens on the Verifications desk.
 */
class KycRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'kycRequests';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Verification history');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['documents', 'requester:id,name', 'reviewer:id,name']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#'),
                KycRequestResource::status(TextColumn::make('status')->label(__('Status'))),
                TextColumn::make('files')->label(__('Files'))
                    ->state(fn (KycRequest $record) => $record->documents->map(fn (KycDocument $document) => $document->type->label())->implode(', '))
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('requester.name')->label(__('Asked by')),
                TextColumn::make('submitted_at')->label(__('Sent'))->dateTime('j M Y, H:i')->placeholder('—'),
                TextColumn::make('reviewer.name')->label(__('Reviewed by'))->placeholder('—')
                    ->description(fn (KycRequest $record) => $record->rejection_reason),
                TextColumn::make('reviewed_at')->label(__('Reviewed'))->dateTime('j M Y, H:i')->placeholder('—'),
            ])
            ->recordUrl(fn (KycRequest $record) => KycRequestResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading(__('No verifications yet'));
    }
}
