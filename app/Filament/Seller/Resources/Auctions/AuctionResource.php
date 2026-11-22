<?php

namespace App\Filament\Seller\Resources\Auctions;

use App\Enums\AuctionStatus;
use App\Filament\Seller\Resources\Auctions\Pages\CreateAuction;
use App\Filament\Seller\Resources\Auctions\Pages\EditAuction;
use App\Filament\Seller\Resources\Auctions\Pages\ListAuctions;
use App\Filament\Seller\Resources\Auctions\Pages\ViewAuction;
use App\Filament\Support\MoneyInput;
use App\Models\Auction;
use App\Models\Company;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The company's auctions: prepared as drafts, sent for review, approved by
 * the platform with the real schedule. Saving goes through SaveAuction, the
 * lifecycle through the actions in AuctionActions.
 */
class AuctionResource extends Resource
{
    protected static ?string $model = Auction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['product:id,name', 'product.coverImage']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make(__('Item'))
                    ->columnSpan(2)
                    ->schema([
                        Select::make('product_id')
                            ->label(__('Product'))
                            ->options(fn (?Auction $record) => self::productOptions($record))
                            ->default(fn () => self::requestedProduct()?->id)
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                                if (blank($get('title')) && filled($state)) {
                                    $set('title', Product::query()->whereKey($state)->value('name'));
                                }
                            })
                            ->helperText(__('Only products without a running auction are listed. Add pictures to the product first.')),
                        TextInput::make('title')
                            ->label(__('Auction title'))
                            ->default(fn () => self::requestedProduct()?->name)
                            ->required()
                            ->minLength(3)
                            ->maxLength(255),
                    ]),
                Section::make(__('Schedule'))
                    ->description(__('The platform confirms the final times when it approves the auction.'))
                    ->columnSpan(1)
                    ->schema([
                        DateTimePicker::make('proposed_starts_at')
                            ->label(__('Start'))
                            ->seconds(false)
                            ->default(fn () => now()->addDay()->startOfHour())
                            ->required(),
                        DateTimePicker::make('proposed_ends_at')
                            ->label(__('End'))
                            ->seconds(false)
                            ->default(fn () => now()->addDays(8)->startOfHour())
                            ->required()
                            ->helperText(__('Between :min minutes and :max days after the start.', [
                                'min' => config('auction.listing.min_duration_minutes'),
                                'max' => config('auction.listing.max_duration_days'),
                            ])),
                    ]),
                Section::make(__('Prices'))
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        MoneyInput::make('starting_price')->label(__('Starting price'))->required()->helperText(__('The first bid must reach this.')),
                        MoneyInput::make('min_increment')->label(__('Bid increment'))->required()->helperText(__('Each new bid must beat the last by at least this.')),
                        MoneyInput::make('reserve_price')->label(__('Reserve price'))->helperText(__('Optional. Not sold below it; bidders only see whether it is met.')),
                        MoneyInput::make('buy_now_price')->label(__('Buy-now price'))->helperText(__('Optional. A buyer can end the auction at once by paying this.')),
                    ]),
            ]);
    }

    /** The product named in "?product=" (from "List in an auction"), when it can be listed. */
    private static function requestedProduct(): ?Product
    {
        $id = request()->integer('product');

        return $id > 0 && array_key_exists($id, self::productOptions(null))
            ? Product::query()->find($id)
            : null;
    }

    /**
     * Products of this company that can be listed: not sold (a cancelled
     * order does not count), not deleted and
     * not in another auction that has not finished.
     *
     * @return array<int, string>
     */
    private static function productOptions(?Auction $record): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return [];
        }

        $finished = [AuctionStatus::Sold, AuctionStatus::Unsold, AuctionStatus::Cancelled];

        return Product::query()
            ->where('company_id', $tenant->id)
            ->where(fn (Builder $query) => $query
                ->whereDoesntHave('auctions', fn (Builder $auctions) => $auctions
                    ->where(fn (Builder $open) => $open->whereNotIn('status', $finished)->orWhere(fn (Builder $sold) => $sold->sold()))
                    ->when($record, fn (Builder $q) => $q->whereKeyNot($record->id)))
                ->when($record, fn (Builder $q) => $q->orWhereKey($record->product_id)))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                ImageColumn::make('product.coverImage.thumbnail_path')
                    ->label('')
                    ->disk('public')
                    ->imageSize(48)
                    ->square(),
                TextColumn::make('title')
                    ->label(__('Auction'))
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (Auction $record) => $record->product?->name),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (AuctionStatus $state) => $state->label())
                    ->color(fn (AuctionStatus $state) => $state->color()),
                TextColumn::make('current_price')
                    ->label(__('Price'))
                    ->formatStateUsing(fn ($state) => $state->format())
                    ->description(fn (Auction $record) => $record->bid_count > 0 ? trans_choice(':count bid|:count bids', $record->bid_count) : null)
                    ->alignEnd(),
                TextColumn::make('starts')
                    ->label(__('Starts'))
                    ->state(fn (Auction $record) => $record->starts_at ?? $record->proposed_starts_at)
                    ->dateTime('j M Y, H:i')
                    ->description(fn (Auction $record) => $record->starts_at ? null : __('Proposed'))
                    ->placeholder('—'),
                TextColumn::make('ends')
                    ->label(__('Ends'))
                    ->state(fn (Auction $record) => $record->ends_at ?? $record->proposed_ends_at)
                    ->dateTime('j M Y, H:i')
                    ->placeholder('—'),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    AuctionActions::submit(),
                    AuctionActions::withdraw(),
                    AuctionActions::feature(),
                    AuctionActions::cancel(),
                ]),
            ])
            ->emptyStateHeading(__('No auctions here'))
            ->emptyStateDescription(__('Create an auction for one of your products and send it for review.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuctions::route('/'),
            'create' => CreateAuction::route('/create'),
            'view' => ViewAuction::route('/{record}'),
            'edit' => EditAuction::route('/{record}/edit'),
        ];
    }
}
