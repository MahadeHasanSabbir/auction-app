<?php

namespace App\Filament\Seller\Resources\Products\Pages;

use App\Filament\Seller\Resources\Auctions\AuctionResource;
use App\Filament\Seller\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/** @property-read Product $record */
class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load('images');
    }

    public function getTitle(): string
    {
        return $this->record->name;
    }

    public function getSubheading(): ?string
    {
        return $this->record->isLocked()
            ? __('An auction of this product is under review or approved, so its details cannot change.')
            : null;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make(__('Pictures'))
                    ->columnSpanFull()
                    ->schema([
                        ImageEntry::make('images.thumbnail_path')
                            ->hiddenLabel()
                            ->disk('public')
                            ->imageHeight(140)
                            ->placeholder(__('No pictures yet. An auction needs at least one.')),
                    ]),
                Section::make(__('Item'))
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('category.name')->label(__('Category')),
                        TextEntry::make('condition')->label(__('Condition'))->formatStateUsing(fn ($state) => $state?->label())->placeholder('—'),
                        TextEntry::make('description')->label(__('Description'))->placeholder('—')->columnSpanFull()->prose(),
                    ]),
                Section::make(__('Details'))
                    ->columnSpan(1)
                    ->schema([
                        KeyValueEntry::make('attributes')
                            ->hiddenLabel()
                            ->keyLabel(__('Property'))
                            ->valueLabel(__('Value'))
                            ->placeholder('—'),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('list')
                ->label(__('List in an auction'))
                ->icon(Heroicon::OutlinedMegaphone)
                ->visible(fn () => ! $this->record->hasOpenAuction() && ! $this->record->isSold())
                ->url(fn () => AuctionResource::getUrl('create', ['product' => $this->record->id])),
            EditAction::make(),
            ProductResource::deleteAction(),
        ];
    }
}
