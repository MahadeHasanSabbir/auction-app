<?php

namespace App\Filament\Seller\Resources\Products;

use App\Actions\Catalogue\DeleteProduct;
use App\Enums\AuctionStatus;
use App\Enums\ProductCondition;
use App\Filament\Seller\Resources\Auctions\AuctionResource;
use App\Filament\Seller\Resources\Products\Pages\CreateProduct;
use App\Filament\Seller\Resources\Products\Pages\EditProduct;
use App\Filament\Seller\Resources\Products\Pages\ListProducts;
use App\Filament\Seller\Resources\Products\Pages\ViewProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * The company's items. A product is described once, with its pictures, and
 * then listed in an auction; while an auction of it is under review or
 * approved, its details are frozen. Saving goes through SaveProduct.
 */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['category:id,name', 'coverImage'])
            ->withCount('auctions')
            // Read by Product::isLocked(), so the list costs no query per row.
            ->withExists(['auctions as is_locked' => fn (Builder $auctions) => $auctions->whereIn('status', AuctionStatus::active())]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make(__('Item'))
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->minLength(3)
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Select::make('category_id')
                            ->label(__('Category'))
                            ->options(fn () => Category::selectOptions())
                            ->searchable()
                            ->required(),
                        Select::make('condition')
                            ->label(__('Condition'))
                            ->options(collect(ProductCondition::cases())->mapWithKeys(fn (ProductCondition $c) => [$c->value => $c->label()])->all()),
                        Textarea::make('description')
                            ->label(__('Description'))
                            ->helperText(__('What it is, its history and any flaws. Buyers decide from this and the pictures.'))
                            ->rows(8)
                            ->maxLength(10000)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('Details'))
                    ->description(__('Size, material, maker, year: anything a buyer would ask.'))
                    ->columnSpan(1)
                    ->schema([
                        KeyValue::make('attributes')
                            ->hiddenLabel()
                            ->keyLabel(__('Property'))
                            ->valueLabel(__('Value'))
                            ->addActionLabel(__('Add a detail')),
                    ]),
                Section::make(__('Pictures'))
                    ->description(trans_choice('Up to :count picture, JPG, PNG or WebP, 8 MB each. Drag to reorder; the first is the cover.|Up to :count pictures, JPG, PNG or WebP, 8 MB each. Drag to reorder; the first is the cover.', (int) config('auction.listing.max_images')))
                    ->columnSpanFull()
                    ->schema([
                        FileUpload::make('images')
                            ->hiddenLabel()
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->appendFiles()
                            ->panelLayout('grid')
                            ->disk('public')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxFiles((int) config('auction.listing.max_images'))
                            ->maxSize(8192)
                            // Stored by SaveProduct through ImageStore, not by the field.
                            ->storeFiles(false),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                ImageColumn::make('coverImage.thumbnail_path')
                    ->label('')
                    ->disk('public')
                    ->imageSize(48)
                    ->square()
                    ->defaultImageUrl(null),
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (Product $record) => $record->condition?->label()),
                TextColumn::make('category.name')->label(__('Category')),
                TextColumn::make('auctions_count')->label(__('Auctions'))->alignEnd(),
                TextColumn::make('is_locked')
                    ->label(__('Editing'))
                    ->badge()
                    ->state(fn (Product $record) => $record->isLocked() ? __('Frozen') : __('Open'))
                    ->color(fn (Product $record) => $record->isLocked() ? 'warning' : 'gray')
                    ->tooltip(fn (Product $record) => $record->isLocked() ? __('An auction of this product is under review or approved.') : null),
                TextColumn::make('updated_at')->label(__('Updated'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label(__('Category'))
                    ->relationship('category', 'name'),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    Action::make('list')
                        ->label(__('List in an auction'))
                        ->icon(Heroicon::OutlinedMegaphone)
                        ->url(fn (Product $record) => AuctionResource::getUrl('create', ['product' => $record->id])),
                    self::deleteAction(),
                ]),
            ])
            ->emptyStateHeading(__('No products yet'))
            ->emptyStateDescription(__('Add an item with its pictures, then list it in an auction.'));
    }

    public static function deleteAction(): Action
    {
        return Action::make('delete')
            ->label(__('Delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (Product $record) => __('Delete :name?', ['name' => $record->name]))
            ->modalDescription(__('Past auctions keep showing it. A product with an auction that has not finished cannot be deleted.'))
            ->authorize('delete')
            ->action(function (Product $record, Action $action): void {
                try {
                    app(DeleteProduct::class)->handle(self::user(), $record);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title((string) collect($e->errors())->flatten()->first())->send();
                    $action->halt();
                }

                Notification::make()->success()->title(__('Product deleted.'))->send();
                $action->redirect(self::getUrl('index'));
            });
    }

    public static function user(): User
    {
        return Auth::user() ?? abort(401);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'view' => ViewProduct::route('/{record}'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
