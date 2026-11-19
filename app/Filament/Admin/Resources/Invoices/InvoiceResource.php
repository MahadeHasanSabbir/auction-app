<?php

namespace App\Filament\Admin\Resources\Invoices;

use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Support\InvoiceTable;
use App\Models\Invoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Every invoice and receipt the platform issued, with its PDF. Nothing is edited. */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 49;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationGroup(): string
    {
        return __('Reports');
    }

    public static function getNavigationLabel(): string
    {
        return __('Invoices');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return InvoiceTable::configure($table, forPlatform: true);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
        ];
    }
}
