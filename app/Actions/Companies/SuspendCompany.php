<?php

namespace App\Actions\Companies;

use App\Actions\Companies\Concerns\LogsAdminActions;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Stops a company from selling or growing its team. Its data, wallet and running orders stay. */
final class SuspendCompany
{
    use LogsAdminActions;

    public function handle(User $owner, Company $company, string $reason): Company
    {
        Gate::forUser($owner)->authorize('update', $company);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('Give a reason of up to 500 characters.')]);
        }

        return DB::transaction(function () use ($owner, $company, $reason): Company {
            $locked = Company::query()->lockForUpdate()->findOrFail($company->id);

            if ($locked->status === CompanyStatus::Suspended) {
                throw ValidationException::withMessages(['company' => __('This company is already suspended.')]);
            }

            $locked->forceFill([
                'status' => CompanyStatus::Suspended,
                'suspended_at' => now(),
                'suspension_reason' => $reason,
            ])->save();
            $this->record($owner, $locked, 'suspended', ['reason' => $reason]);

            return $company->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
