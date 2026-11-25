<?php

namespace App\Filament\Support;

use App\Enums\InvoiceKind;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** The invoice list, shared by the admin resource and the seller page. */
final class InvoiceTable
{
    public static function configure(Table $table, bool $forPlatform): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns(array_values(array_filter([
                TextColumn::make('number')->label(__('Number'))->searchable()->weight('medium'),
                TextColumn::make('kind')->label(__('Document'))->formatStateUsing(fn (InvoiceKind $state) => $state->label()),
                $forPlatform ? TextColumn::make('billed_name')->label(__('Billed to'))->searchable()->description(fn (Invoice $record) => $record->billed_email) : null,
                TextColumn::make('issued_at')->label(__('Issued'))->dateTime('j M Y, H:i')->sortable(),
                TextColumn::make('total')->label(__('Total'))->formatStateUsing(fn ($state) => $state->format())->alignEnd(),
                TextColumn::make('tax')->label(__('VAT'))->formatStateUsing(fn ($state) => $state->isZero() ? '—' : $state->format())->alignEnd(),
            ])))
            ->filters([
                SelectFilter::make('kind')->label(__('Document'))
                    ->options(collect(InvoiceKind::cases())->mapWithKeys(fn (InvoiceKind $kind) => [$kind->value => $kind->label()])->all()),
            ])
            ->recordActions([
                Action::make('download')
                    ->label(__('PDF'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (Invoice $record) => route('invoices.pdf', $record)),
            ])
            ->emptyStateHeading(__('No invoices yet'));
    }
}
