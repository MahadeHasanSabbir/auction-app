<?php

namespace App\Filament\Admin\Resources\Plans;

use App\Filament\Admin\Resources\Plans\Pages\ManagePlans;
use App\Filament\Support\MoneyInput;
use App\Models\Plan;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/** The plans companies subscribe to. System owner only (PlanPolicy). */
class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 22;

    public static function getNavigationGroup(): string
    {
        return __('Marketplace');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('subscriptions');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, $set, $get) => filled($get('slug')) ? null : $set('slug', Str::slug((string) $state))),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->alphaDash()
                    ->unique(ignoreRecord: true),
                TextInput::make('description')
                    ->maxLength(255)
                    ->columnSpanFull(),
                MoneyInput::make('price_monthly')
                    ->label(__('Monthly price'))
                    ->required(),
                MoneyInput::make('price_yearly')
                    ->label(__('Yearly price'))
                    ->helperText(__('Leave empty to sell this plan monthly only.')),
                TextInput::make('max_users')
                    ->label(__('Team seats'))
                    ->helperText(__('The company owner counts as one.'))
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(1000),
                TextInput::make('max_active_auctions')
                    ->label(__('Active auctions'))
                    ->helperText(__('Leave empty for unlimited.'))
                    ->integer()
                    ->minValue(1),
                TextInput::make('trial_days')
                    ->label(__('Free trial days'))
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(365)
                    ->default(0),
                TextInput::make('sort_order')
                    ->required()
                    ->integer()
                    ->default(0),
                TagsInput::make('features')
                    ->label(__('Feature list'))
                    ->helperText(__('Extra lines shown on the plan card.'))
                    ->columnSpanFull(),
                Toggle::make('is_public')
                    ->label(__('Offered to every company'))
                    ->helperText(__('Switch off for a custom plan that only the system owner can assign.'))
                    ->default(true),
                Toggle::make('is_active')
                    ->label(__('Active'))
                    ->helperText(__('An inactive plan cannot be chosen; companies already on it keep it.'))
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('price_monthly')->label(__('Monthly'))->formatStateUsing(fn ($state) => $state->format()),
                TextColumn::make('price_yearly')->label(__('Yearly'))->formatStateUsing(fn ($state) => $state?->format())->placeholder('—'),
                TextColumn::make('max_users')->label(__('Seats')),
                TextColumn::make('max_active_auctions')->label(__('Auctions'))->placeholder(__('Unlimited')),
                TextColumn::make('trial_days')->label(__('Trial days')),
                TextColumn::make('subscriptions_count')->label(__('Companies')),
                IconColumn::make('is_public')->label(__('Public'))->boolean()->falseIcon('heroicon-o-minus')->falseColor('gray'),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                // A plan companies have used is deactivated, not deleted.
                DeleteAction::make()->visible(fn (Plan $record) => $record->subscriptions_count === 0 && auth()->user()?->can('delete', $record)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePlans::route('/'),
        ];
    }
}
