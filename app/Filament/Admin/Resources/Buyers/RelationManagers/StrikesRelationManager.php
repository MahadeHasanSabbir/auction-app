<?php

namespace App\Filament\Admin\Resources\Buyers\RelationManagers;

use App\Actions\Trust\RevokeStrike;
use App\Filament\Support\Outcome;
use App\Models\Strike;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/** The buyer's strikes, active ones first; staff can revoke one. */
class StrikesRelationManager extends RelationManager
{
    protected static string $relationship = 'strikes';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('issuer:id,name'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('reason')->label(__('Reason'))->wrap()
                    ->description(fn (Strike $record) => $record->source->label()),
                TextColumn::make('state')->label(__('State'))->badge()
                    ->state(fn (Strike $record) => match (true) {
                        $record->revoked_at !== null => __('Revoked'),
                        $record->expires_at->isPast() => __('Expired'),
                        default => __('Active'),
                    })
                    ->color(fn (string $state) => $state === __('Active') ? 'danger' : 'gray'),
                TextColumn::make('issuer.name')->label(__('By'))->placeholder(__('System')),
                TextColumn::make('created_at')->label(__('Given'))->date('j M Y'),
                TextColumn::make('expires_at')->label(__('Counts until'))->date('j M Y'),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label(__('Revoke'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->requiresConfirmation()
                    ->modalDescription(__('The strike stops counting. A suspension it caused stays until you reactivate the account.'))
                    ->visible(fn (Strike $record) => Gate::allows('revoke', $record))
                    ->action(Outcome::of(fn (Strike $record) => app(RevokeStrike::class)->handle(Outcome::user(), $record), __('Strike revoked.'))),
            ])
            ->emptyStateHeading(__('No strikes'));
    }
}
