<?php

namespace App\Filament\Admin\Resources\Categories;

use App\Filament\Admin\Resources\Categories\Pages\ManageCategories;
use App\Models\Category;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The category tree, two levels deep. Platform staff keep it; a category
 * that has products or sub-categories is switched off rather than deleted
 * (CategoryPolicy).
 */
class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): string
    {
        return __('Marketplace');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('parent:id,name')->withCount(['products', 'children']);
    }

    public static function form(Schema $schema): Schema
    {
        $hasChildren = fn (?Category $record) => $record !== null && $record->children()->exists();

        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(100)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, $set, $get) => filled($get('slug')) ? null : $set('slug', Str::slug((string) $state))),
                TextInput::make('slug')->label(__('Slug'))->required()->maxLength(120)->alphaDash()->unique(ignoreRecord: true),
                Select::make('parent_id')
                    ->label(__('Inside'))
                    ->placeholder(__('Top level'))
                    // Two levels only: a parent is a top-level category, never the category itself.
                    ->options(fn (?Category $record) => Category::query()->whereNull('parent_id')
                        ->when($record !== null, fn (Builder $query) => $query->whereKeyNot($record?->id))
                        ->orderBy('name')->pluck('name', 'id')->all())
                    ->disabled($hasChildren)
                    ->helperText(fn (?Category $record) => $hasChildren($record) ? __('It has sub-categories, so it stays at the top level.') : null),
                TextInput::make('sort_order')->label(__('Order'))->integer()->minValue(0)->maxValue(9999)->default(0),
                TextInput::make('description')->label(__('Description'))->maxLength(255)->columnSpanFull(),
                Toggle::make('is_active')->label(__('Offered to sellers'))->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->weight('medium')
                    ->description(fn (Category $record) => $record->parent !== null ? __('in :parent', ['parent' => $record->parent->name]) : null),
                TextColumn::make('slug')->label(__('Slug'))->color('gray')->toggleable(),
                TextColumn::make('children_count')->label(__('Sub-categories'))->alignEnd(),
                TextColumn::make('products_count')->label(__('Products'))->alignEnd(),
                TextColumn::make('sort_order')->label(__('Order'))->sortable()->alignEnd(),
                ToggleColumn::make('is_active')->label(__('Offered')),
            ])
            ->filters([
                SelectFilter::make('parent_id')->label(__('Inside'))
                    ->options(fn () => Category::query()->whereNull('parent_id')->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCategories::route('/'),
        ];
    }
}
