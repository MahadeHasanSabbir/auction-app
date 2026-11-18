<?php

namespace App\Filament\Support\Reports;

use App\Enums\AuctionCloseReason;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Support\Money;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Auctions that closed in a period, sold or not: how many sold, how much
 * bidding they drew and what they fetched. Cancelled auctions are left out.
 */
abstract class AuctionResultsReportPage extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static ?string $slug = 'reports/auctions';

    abstract protected function forPlatform(): bool;

    /** @return Builder<Auction> */
    abstract protected function auctions(): Builder;

    public static function getNavigationLabel(): string
    {
        return __('Auction results');
    }

    public function getTitle(): string
    {
        return __('Auction results');
    }

    public function getSubheading(): string
    {
        return __('Auctions that ended in the period, sold or not.');
    }

    public function table(Table $table): Table
    {
        $money = fn (?Money $state) => $state?->format();

        return $table
            ->query($this->auctions()
                ->whereIn('auctions.status', [AuctionStatus::Sold, AuctionStatus::Unsold])
                ->with('company:id,name'))
            ->defaultSort('closed_at', 'desc')
            ->columns([
                TextColumn::make('closed_at')->label(__('Ended'))->dateTime('j M Y')->sortable(),
                TextColumn::make('title')->label(__('Auction'))->searchable()->weight('medium')->wrap(),
                TextColumn::make('company.name')->label(__('Seller'))->visible($this->forPlatform()),
                TextColumn::make('close_reason')->label(__('Outcome'))->badge()
                    ->formatStateUsing(fn (AuctionCloseReason $state) => $state->label())
                    ->color(fn (Auction $record) => $record->status === AuctionStatus::Sold ? 'success' : 'gray'),
                TextColumn::make('bid_count')->label(__('Bids'))->numeric()->alignEnd()->sortable(),
                TextColumn::make('starting_price')->label(__('Starting price'))->formatStateUsing($money)->alignEnd(),
                TextColumn::make('final_price')->label(__('Sold for'))->formatStateUsing($money)->placeholder('—')->alignEnd()->sortable(),
            ])
            ->filters([
                self::periodFilter('auctions.closed_at'),
                SelectFilter::make('close_reason')->label(__('Outcome'))->multiple()
                    ->options(collect([AuctionCloseReason::Sold, AuctionCloseReason::BuyNow, AuctionCloseReason::ReserveNotMet, AuctionCloseReason::NoBids])
                        ->mapWithKeys(fn (AuctionCloseReason $r) => [$r->value => $r->label()])),
                SelectFilter::make('company_id')->label(__('Seller'))->relationship('company', 'name')->searchable()->preload()
                    ->visible($this->forPlatform()),
            ])
            ->filtersFormColumns(2)
            ->emptyStateHeading(__('No auctions ended in this period'));
    }

    public function summary(): array
    {
        $totals = $this->filteredQuery()->toBase()
            ->selectRaw('count(*) as ended, sum(case when status = ? then 1 else 0 end) as sold, coalesce(sum(bid_count), 0) as bids, coalesce(sum(final_price), 0) as value', [AuctionStatus::Sold->value])
            ->first();

        $ended = (int) ($totals->ended ?? 0);
        $sold = (int) ($totals->sold ?? 0);

        return [
            __('Ended') => number_format($ended),
            __('Sold') => $ended === 0 ? '0' : __(':sold (:rate%)', ['sold' => number_format($sold), 'rate' => intdiv($sold * 100, $ended)]),
            __('Bids') => number_format((int) ($totals->bids ?? 0)),
            __('Sold for') => Money::minor((int) ($totals->value ?? 0))->format(),
        ];
    }

    protected function csvName(): string
    {
        return 'auction-results';
    }

    protected function csvHeader(): array
    {
        return array_values(array_filter([
            __('Ended'), __('Auction'), $this->forPlatform() ? __('Seller') : null, __('Outcome'), __('Bids'),
            __('Starting price'), __('Reserve met'), __('Sold for'), __('Currency'),
        ]));
    }

    protected function csvRow(Model $record): array
    {
        /** @var Auction $record */
        $row = [
            $record->closed_at?->setTimezone((string) config('auction.display_timezone'))->format('Y-m-d H:i'),
            $record->title,
        ];

        if ($this->forPlatform()) {
            $row[] = $record->company?->name;
        }

        return [
            ...$row,
            $record->close_reason?->label(),
            $record->bid_count,
            $record->starting_price->toDecimal(),
            $record->reserve_met ? __('Yes') : __('No'),
            $record->final_price?->toDecimal(),
            $record->starting_price->currency,
        ];
    }
}
