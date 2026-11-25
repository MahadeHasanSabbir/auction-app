<?php

namespace App\Filament\Seller\Resources\Invoices;

use App\Filament\Seller\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Support\InvoiceTable;
use App\Models\Company;
use App\Models\Invoice;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The company's commission invoices, plan invoices, payout receipts and fines, as PDFs. */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 8;

    protected static ?string $recordTitleAttribute = 'number';

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

    /**
     * Scoped to the company here as well as by the panel's tenancy scope:
     * these are financial documents, and the panel's scope is only added
     * once a request passes the tenant middleware.
     */
    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        return parent::getEloquentQuery()->where('company_id', $tenant instanceof Company ? $tenant->id : 0);
    }

    public static function table(Table $table): Table
    {
        return InvoiceTable::configure($table, forPlatform: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
        ];
    }
}
