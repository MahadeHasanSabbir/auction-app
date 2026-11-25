<?php

namespace App\Filament\Admin\Resources\Buyers\RelationManagers;

use App\Enums\LoginMethod;
use App\Models\LoginEvent;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/** Sign-ins and failed passwords, newest first. */
class LoginEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'loginEvents';

    protected static ?string $title = 'Sign-ins';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('When'))->dateTime('j M Y, H:i'),
                TextColumn::make('succeeded')->label(__('Result'))->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? __('Signed in') : __('Failed'))
                    ->color(fn (bool $state) => $state ? 'success' : 'danger'),
                TextColumn::make('method')->label(__('How'))
                    ->formatStateUsing(fn (LoginMethod $state) => $state->label()),
                TextColumn::make('ip_address')->label(__('IP address'))->copyable()->searchable(),
                TextColumn::make('browser')->label(__('Browser'))
                    ->state(fn (LoginEvent $record) => $record->browser())
                    ->tooltip(fn (LoginEvent $record) => $record->user_agent),
            ])
            ->filters([
                TernaryFilter::make('succeeded')->label(__('Result'))->trueLabel(__('Signed in'))->falseLabel(__('Failed')),
            ])
            ->emptyStateHeading(__('No sign-ins recorded'));
    }
}
