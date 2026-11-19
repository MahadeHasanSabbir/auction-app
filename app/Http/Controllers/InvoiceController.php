<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\Invoices\Invoices;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** Downloads an invoice or receipt as a PDF, for whoever may see it. */
class InvoiceController extends Controller
{
    public function show(Invoice $invoice, Invoices $invoices): Response
    {
        Gate::authorize('view', $invoice);

        $response = $invoices->download($invoice);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
