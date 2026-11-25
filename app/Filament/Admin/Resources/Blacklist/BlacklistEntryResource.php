<?php

namespace App\Filament\Admin\Resources\Blacklist;

use App\Enums\BlacklistType;
use App\Filament\Admin\Resources\Blacklist\Pages\ManageBlacklistEntries;
use App\Models\BlacklistEntry;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/**
 * Email addresses, email domains and IP addresses that may not sign up or
 * sign in. Open sessions are not ended: suspend the account for that.
 */
class BlacklistEntryResource extends Resource
{
    protected static ?string $model = BlacklistEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static ?int $navigationSort = 15;

    protected static ?string $slug = 'blacklist';

    protected static ?string $modelLabel = 'blacklist entry';

    public static function getNavigationGroup(): string
    {
        return __('People');
    }

    public static function getNavigationLabel(): string
    {
        return __('Blacklist');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('creator:id,name');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('type')->label(__('Block'))
                    ->options(collect(BlacklistType::cases())->mapWithKeys(fn (BlacklistType $type) => [$type->value => $type->label()])->all())
                    ->default(BlacklistType::Email->value)
                    ->required()
                    ->live()
                    ->selectablePlaceholder(false),
                TextInput::make('value')->label(__('Value'))->required()->maxLength(255)
                    ->rules(fn (Get $get) => match ($get('type')) {
                        BlacklistType::Email->value => ['email'],
                        BlacklistType::Ip->value => ['ip'],
                        default => ['regex:/^@?[a-z0-9.-]+\.[a-z]{2,}$/i'],
                    })
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('type', $get('type')))
                    ->helperText(__('An address (name@example.com), a domain (example.com) or an IP address.')),
                Textarea::make('reason')->label(__('Reason'))->required()->maxLength(500)->rows(2)->columnSpanFull()
                    ->helperText(__('Kept for the team; not shown to anyone blocked.')),
                DateTimePicker::make('expires_at')->label(__('Blocked until'))->seconds(false)->after('now')
                    ->helperText(__('Leave empty to block for good.')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('value')->label(__('Value'))->searchable()->weight('medium')->copyable(),
                TextColumn::make('type')->label(__('Type'))->badge()->formatStateUsing(fn (BlacklistType $state) => $state->label()),
                TextColumn::make('reason')->label(__('Reason'))->limit(60)->wrap(),
                TextColumn::make('expires_at')->label(__('Until'))->dateTime('j M Y, H:i')->placeholder(__('For good')),
                TextColumn::make('creator.name')->label(__('By')),
                TextColumn::make('created_at')->label(__('Added'))->date('j M Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('Type'))
                    ->options(collect(BlacklistType::cases())->mapWithKeys(fn (BlacklistType $type) => [$type->value => $type->label()])->all()),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading(__('Nothing is blocked'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBlacklistEntries::route('/'),
        ];
    }
}
