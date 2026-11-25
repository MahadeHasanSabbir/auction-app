<?php

namespace App\Filament\Admin\Resources\UserTypes;

use App\Enums\AccountKind;
use App\Filament\Admin\Resources\UserTypes\Pages\ManageUserTypes;
use App\Models\UserType;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
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

/**
 * Kinds of buyer and seller account that fee rules can single out (a
 * reseller, a charity, …). System owner only (UserTypePolicy); one that is
 * in use is switched off rather than deleted.
 */
class UserTypeResource extends Resource
{
    protected static ?string $model = UserType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 23;

    public static function getNavigationGroup(): string
    {
        return __('Marketplace');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount(['users', 'companies']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(100)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, $set, $get) => filled($get('slug')) ? null : $set('slug', Str::slug((string) $state))),
                TextInput::make('slug')->label(__('Slug'))->required()->maxLength(100)->alphaDash()->unique(ignoreRecord: true),
                Select::make('account_kind')
                    ->label(__('For'))
                    ->options(collect(AccountKind::cases())->mapWithKeys(fn (AccountKind $kind) => [$kind->value => $kind->label()])->all())
                    ->required()
                    ->disabledOn('edit'),
                TextInput::make('description')->label(__('Description'))->maxLength(255),
                Toggle::make('is_default')->label(__('Given to new accounts'))
                    ->helperText(__('One default per kind; choosing this one clears the other.')),
                Toggle::make('is_active')->label(__('Active'))->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->weight('medium')->description(fn (UserType $record) => $record->description),
                TextColumn::make('account_kind')->label(__('For'))->badge()->color('gray')->formatStateUsing(fn (AccountKind $state) => $state->label()),
                IconColumn::make('is_default')->label(__('Default'))->boolean()->falseIcon('heroicon-o-minus')->falseColor('gray'),
                TextColumn::make('accounts')->label(__('Accounts'))->alignEnd()
                    ->state(fn (UserType $record) => (int) $record->getAttribute('users_count') + (int) $record->getAttribute('companies_count')),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->recordActions([
                EditAction::make()->after(fn (UserType $record) => self::keepOneDefault($record)),
                DeleteAction::make(),
            ]);
    }

    /** At most one default type per account kind. */
    public static function keepOneDefault(UserType $type): void
    {
        if ($type->is_default) {
            UserType::query()->where('account_kind', $type->account_kind)->whereKeyNot($type->id)->update(['is_default' => false]);
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUserTypes::route('/'),
        ];
    }
}
