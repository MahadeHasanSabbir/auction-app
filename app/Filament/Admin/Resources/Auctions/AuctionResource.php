<?php

namespace App\Filament\Admin\Resources\Auctions;

use App\Enums\AuctionStatus;
use App\Filament\Admin\Resources\Auctions\Pages\ListAuctions;
use App\Filament\Admin\Resources\Auctions\Pages\ViewAuction;
use App\Models\Auction;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every company's auctions, opening on the review queue. Platform staff and
 * the system owner approve (with the real schedule), reject with a reason,
 * or cancel an approved auction. Sellers create auctions; nothing is created
 * or edited here.
 */
class AuctionResource extends Resource
{
    protected static ?string $model = Auction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['company:id,name', 'product:id,name', 'product.coverImage']);
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = Auction::query()->where('status', AuctionStatus::PendingReview)->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Awaiting review');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('submitted_at')
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
                    ->description(fn (Auction $record) => $record->company?->name),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (AuctionStatus $state) => $state->label())
                    ->color(fn (AuctionStatus $state) => $state->color()),
                TextColumn::make('starting_price')
                    ->label(__('Starting price'))
                    ->formatStateUsing(fn ($state) => $state->format())
                    ->alignEnd(),
                TextColumn::make('starts')
                    ->label(__('Starts'))
                    ->state(fn (Auction $record) => $record->starts_at ?? $record->proposed_starts_at)
                    ->dateTime('j M Y, H:i')
                    ->description(fn (Auction $record) => $record->starts_at ? null : __('Proposed'))
                    ->placeholder('—'),
                TextColumn::make('submitted_at')
                    ->label(__('Sent'))
                    ->since()
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->label(__('Company'))
                    ->relationship('company', 'name')
                    ->searchable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    AuctionReviewActions::approve(),
                    AuctionReviewActions::reject(),
                    AuctionReviewActions::cancel(),
                ]),
            ])
            ->emptyStateHeading(__('Nothing here'))
            ->emptyStateDescription(__('Auctions sellers send for review appear in this list.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuctions::route('/'),
            'view' => ViewAuction::route('/{record}'),
        ];
    }
}
