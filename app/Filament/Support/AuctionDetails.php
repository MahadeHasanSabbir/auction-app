<?php

namespace App\Filament\Support;

use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Services\Fees\CommissionTerms;
use App\Services\Fees\FeeResolver;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One auction as the seller and the reviewer see it: the item with its
 * pictures, the prices, the proposed and approved schedule, and the review.
 * Expects product.images, product.category and company to be loaded.
 */
final class AuctionDetails
{
    public static function configure(Schema $schema, bool $forPlatform): Schema
    {
        $date = 'j M Y, H:i';
        $money = fn ($state) => $state?->format();

        return $schema
            ->columns(3)
            ->components([
                Section::make(__('Sent back by the platform'))
                    ->icon('heroicon-o-exclamation-triangle')
                    ->iconColor('danger')
                    ->columnSpanFull()
                    ->visible(fn (Auction $record) => $record->status === AuctionStatus::Rejected)
                    ->schema([
                        TextEntry::make('rejection_reason')->hiddenLabel(),
                        TextEntry::make('edit_hint')->hiddenLabel()->color('gray')->state(__('Edit the auction or its product, then send it for review again.'))->visible(! $forPlatform),
                    ]),
                Section::make(__('Cancelled'))
                    ->icon('heroicon-o-x-circle')
                    ->iconColor('gray')
                    ->columnSpanFull()
                    ->visible(fn (Auction $record) => $record->status === AuctionStatus::Cancelled)
                    ->schema([
                        TextEntry::make('close_reason')->hiddenLabel()->formatStateUsing(fn ($state) => $state?->label()),
                        TextEntry::make('cancellation_reason')->hiddenLabel()->visible(fn (Auction $record) => filled($record->cancellation_reason)),
                    ]),

                Section::make(__('Item'))
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        ImageEntry::make('product.images.thumbnail_path')
                            ->hiddenLabel()
                            ->disk('public')
                            ->imageHeight(120)
                            ->columnSpanFull()
                            ->placeholder(__('No pictures.')),
                        TextEntry::make('product.name')->label(__('Product')),
                        TextEntry::make('product.category.name')->label(__('Category')),
                        TextEntry::make('product.condition')->label(__('Condition'))->formatStateUsing(fn ($state) => $state?->label())->placeholder('—'),
                        TextEntry::make('company.name')->label(__('Company'))->visible($forPlatform),
                        TextEntry::make('product.description')->label(__('Description'))->placeholder('—')->columnSpanFull()->prose(),
                        KeyValueEntry::make('product.attributes')->label(__('Details'))->keyLabel(__('Property'))->valueLabel(__('Value'))->columnSpanFull()
                            ->visible(fn (Auction $record) => filled($record->product?->getAttribute('attributes'))),
                    ]),

                Section::make(__('Prices'))
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('starting_price')->label(__('Starting price'))->formatStateUsing($money),
                        TextEntry::make('min_increment')->label(__('Bid increment'))->formatStateUsing($money),
                        TextEntry::make('reserve_price')->label(__('Reserve'))->formatStateUsing($money)->placeholder(__('None')),
                        TextEntry::make('buy_now_price')->label(__('Buy now'))->formatStateUsing($money)->placeholder(__('None')),
                        TextEntry::make('commission')
                            ->label(__('Sale commission'))
                            ->state(fn (Auction $record) => self::commission($record)),
                    ]),

                Section::make(__('Schedule'))
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('proposed_starts_at')->label(__('Proposed start'))->dateTime($date)->placeholder('—'),
                        TextEntry::make('proposed_ends_at')->label(__('Proposed end'))->dateTime($date)->placeholder('—'),
                        TextEntry::make('starts_at')->label(__('Starts'))->dateTime($date)->placeholder(__('Set on approval')),
                        TextEntry::make('ends_at')->label(__('Ends'))->dateTime($date)->placeholder(__('Set on approval')),
                        TextEntry::make('submitted_at')->label(__('Sent for review'))->dateTime($date)->placeholder('—'),
                        TextEntry::make('reviewed_at')->label(__('Reviewed'))->dateTime($date)->placeholder('—'),
                        TextEntry::make('reviewer.name')->label(__('Reviewed by'))->placeholder(fn (Auction $record) => $record->reviewed_at ? __('Approved automatically') : '—')->visible($forPlatform),
                        TextEntry::make('creator.name')->label(__('Created by')),
                    ]),
            ]);
    }

    /** The terms fixed at approval, or what approval would fix today. */
    private static function commission(Auction $record): string
    {
        $snapshot = $record->fee_snapshot['sale_commission'] ?? null;

        if (is_array($snapshot)) {
            return CommissionTerms::fromSnapshot($snapshot)->describe();
        }

        return __(':terms (fixed when approved)', ['terms' => app(FeeResolver::class)->saleCommission($record->company)->describe()]);
    }
}
