<?php

namespace App\Actions\Companies;

use App\Actions\Companies\Concerns\LogsAdminActions;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** Trusted companies skip the platform's review of each auction. */
final class SetAutoApprove
{
    use LogsAdminActions;

    public function handle(User $owner, Company $company, bool $enabled): Company
    {
        Gate::forUser($owner)->authorize('update', $company);

        if ($company->auto_approve !== $enabled) {
            $company->forceFill(['auto_approve' => $enabled])->save();
            $this->record($owner, $company, $enabled ? 'auto_approve_on' : 'auto_approve_off');
        }

        return $company;
    }
}
