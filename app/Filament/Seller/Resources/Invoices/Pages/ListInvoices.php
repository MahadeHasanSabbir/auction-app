<?php

namespace App\Filament\Seller\Resources\Invoices\Pages;

use App\Filament\Seller\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;
}
