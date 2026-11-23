<?php

namespace App\Filament\Admin\Resources\Companies\RelationManagers;

use App\Enums\CommissionBasis;
use App\Enums\FeeUseCase;
use App\Filament\Support\MoneyInput;
use App\Models\CompanyAgreement;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Commission terms agreed with one company. They replace the default sale
 * commission for auctions approved while they are in force.
 */
class AgreementsRelationManager extends RelationManager
{
    protected static string $relationship = 'agreements';

    protected static ?string $title = 'Commission agreements';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('basis')
                    ->label(__('Commission is taken on'))
                    ->options(collect(CommissionBasis::cases())->mapWithKeys(fn (CommissionBasis $basis) => [$basis->value => $basis->label()])->all())
                    ->default(CommissionBasis::FinalPrice->value)
                    ->required(),
                // Stored as basis points, typed as a percentage.
                TextInput::make('rate_bps')
                    ->label(__('Rate (%)'))
                    ->helperText(__('For example 7.5.'))
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.01)
                    ->formatStateUsing(fn (mixed $state) => $state === null ? null : $state / 100)
                    ->dehydrateStateUsing(fn (mixed $state) => (int) round((float) $state * 100)),
                MoneyInput::make('fixed_amount')->label(__('Fixed amount on top'))->default('0'),
                MoneyInput::make('min_amount')->label(__('Smallest commission')),
                MoneyInput::make('max_amount')->label(__('Largest commission')),
                DateTimePicker::make('effective_from')->label(__('Starts'))->required()->default(now()),
                DateTimePicker::make('effective_until')->label(__('Ends'))->helperText(__('Leave empty for no end date.'))->after('effective_from'),
                Textarea::make('note')->label(__('Note'))->rows(2)->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query) => $query->latest('effective_from'))
            ->columns([
                TextColumn::make('rate_bps')->label(__('Rate'))->formatStateUsing(fn (int $state) => rtrim(rtrim(number_format($state / 100, 2), '0'), '.').'%'),
                TextColumn::make('basis')->formatStateUsing(fn (CommissionBasis $state) => $state->label()),
                TextColumn::make('fixed_amount')->label(__('Fixed'))->formatStateUsing(fn ($state) => $state->isZero() ? '—' : $state->format()),
                TextColumn::make('effective_from')->label(__('Starts'))->dateTime('j M Y, H:i'),
                TextColumn::make('effective_until')->label(__('Ends'))->dateTime('j M Y, H:i')->placeholder(__('No end')),
                TextColumn::make('note')->limit(40)->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()->mutateDataUsing(fn (array $data) => [
                    ...$data,
                    'use_case' => FeeUseCase::SaleCommission->value,
                    'created_by' => Auth::id(),
                ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                Action::make('end')
                    ->label(__('End now'))
                    ->icon('heroicon-o-stop-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(__('New auctions use the default commission (or a newer agreement). Auctions already approved keep this one.'))
                    ->visible(fn (CompanyAgreement $record) => Gate::allows('end', $record))
                    ->action(function (CompanyAgreement $record): void {
                        Gate::authorize('end', $record);
                        $record->forceFill(['effective_until' => now()])->save();
                        Notification::make()->success()->title(__('Agreement ended.'))->send();
                    }),
            ]);
    }

    /** Relation managers are read-only on a company's page by default; the system owner edits agreements here. */
    public function isReadOnly(): bool
    {
        return false;
    }
}
