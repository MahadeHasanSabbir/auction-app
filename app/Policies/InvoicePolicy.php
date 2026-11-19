<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

/** A buyer sees their own documents, a company team its company's, platform staff all. */
final class InvoicePolicy
{
    /** Platform staff in /admin, a company team in its own /seller (scoped to the company). */
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->isPlatform() || $user->isSeller());
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->isPlatform()
            || ($invoice->user_id !== null && $invoice->user_id === $user->id)
            || ($invoice->company_id !== null && $user->isSeller() && $invoice->company_id === $user->company_id);
    }
}
