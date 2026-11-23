<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\KycRequest;
use App\Models\User;

final class KycRequestPolicy
{
    /** The admin panel's verification desk. */
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isPlatform();
    }

    /**
     * Staff, and the buyer or the company owner the documents belong to. A
     * company's documents include the owner's identity papers, so members
     * do not see them.
     */
    public function view(User $user, KycRequest $request): bool
    {
        return ($user->is_active && $user->isPlatform())
            || ($request->user_id !== null && $request->user_id === $user->id)
            || ($request->company_id !== null && $request->company_id === $user->company_id && $user->role === UserRole::CompanyOwner && $user->is_active);
    }

    /** Asking for documents, approving and rejecting. */
    public function review(User $user): bool
    {
        return $user->is_active && $user->isPlatform();
    }

    /** A buyer verifies themselves; a company's owner verifies the company. */
    public function submit(User $user, User|Company $subject): bool
    {
        return $subject instanceof Company
            ? $user->can('manageProfile', $subject)
            : $user->is($subject) && $user->isBuyer();
    }
}
