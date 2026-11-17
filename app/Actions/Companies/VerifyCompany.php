<?php

namespace App\Actions\Companies;

use App\Actions\Companies\Concerns\LogsAdminActions;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** The system owner confirms a pending company is genuine; it may list once its plan allows. */
final class VerifyCompany
{
    use LogsAdminActions;

    public function handle(User $owner, Company $company): Company
    {
        Gate::forUser($owner)->authorize('update', $company);

        return DB::transaction(function () use ($owner, $company): Company {
            $locked = Company::query()->lockForUpdate()->findOrFail($company->id);

            if ($locked->status !== CompanyStatus::Pending) {
                throw ValidationException::withMessages(['company' => __('Only a company awaiting verification can be verified.')]);
            }

            $locked->forceFill(['status' => CompanyStatus::Active, 'verified_at' => now()])->save();
            $this->record($owner, $locked, 'verified');

            return $company->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
