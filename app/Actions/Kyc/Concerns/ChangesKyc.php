<?php

namespace App\Actions\Kyc\Concerns;

use App\Enums\KycStatus;
use App\Models\Company;
use App\Models\KycRequest;
use App\Models\User;

/** Shared by the KYC actions: the subject row, locked, and the activity log. */
trait ChangesKyc
{
    private function lockSubject(User|Company $subject): User|Company
    {
        return $subject::query()->lockForUpdate()->findOrFail($subject->getKey());
    }

    private function setStatus(User|Company $subject, KycStatus $status): void
    {
        $subject->forceFill(['kyc_status' => $status])->save();
    }

    private function subjectOf(KycRequest $request): User|Company
    {
        return $request->company_id !== null
            ? Company::query()->findOrFail($request->company_id)
            : User::query()->findOrFail($request->user_id);
    }

    /** @param  array<string, mixed>  $properties */
    private function log(User $by, KycRequest $request, string $event, array $properties = []): void
    {
        activity('admin')
            ->causedBy($by)
            ->performedOn($request)
            ->event($event)
            ->withProperties(array_filter($properties, fn (mixed $value) => $value !== null))
            ->log("kyc {$event} {$request->id}");
    }
}
