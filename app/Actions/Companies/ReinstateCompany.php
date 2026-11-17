<?php

namespace App\Actions\Companies;

use App\Actions\Companies\Concerns\LogsAdminActions;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Lifts a suspension: back to active if the company was verified before, otherwise to pending. */
final class ReinstateCompany
{
    use LogsAdminActions;

    public function handle(User $owner, Company $company): Company
    {
        Gate::forUser($owner)->authorize('update', $company);

        return DB::transaction(function () use ($owner, $company): Company {
            $locked = Company::query()->lockForUpdate()->findOrFail($company->id);

            if ($locked->status !== CompanyStatus::Suspended) {
                throw ValidationException::withMessages(['company' => __('This company is not suspended.')]);
            }

            $locked->forceFill([
                'status' => $locked->verified_at === null ? CompanyStatus::Pending : CompanyStatus::Active,
                'suspended_at' => null,
                'suspension_reason' => null,
            ])->save();
            $this->record($owner, $locked, 'reinstated');

            return $company->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
