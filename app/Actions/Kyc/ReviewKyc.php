<?php

namespace App\Actions\Kyc;

use App\Actions\Kyc\Concerns\ChangesKyc;
use App\Enums\KycStatus;
use App\Models\KycRequest;
use App\Models\User;
use App\Services\Notifications\AccountAlerts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Platform staff approve submitted documents (the account is cleared) or
 * reject them with a reason (the account uploads again).
 */
final class ReviewKyc
{
    use ChangesKyc;

    public function __construct(private readonly AccountAlerts $alerts) {}

    public function approve(User $staff, KycRequest $request): KycRequest
    {
        return $this->decide($staff, $request, KycStatus::Approved, null);
    }

    public function reject(User $staff, KycRequest $request, string $reason): KycRequest
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('Say what is wrong, so they can fix it.')]);
        }

        return $this->decide($staff, $request, KycStatus::Rejected, mb_substr($reason, 0, 500));
    }

    private function decide(User $staff, KycRequest $request, KycStatus $decision, ?string $reason): KycRequest
    {
        Gate::forUser($staff)->authorize('review', KycRequest::class);

        DB::transaction(function () use ($staff, $request, $decision, $reason): void {
            // The subject first, then the request, as in SubmitKyc.
            $subject = $this->lockSubject($this->subjectOf($request));
            $locked = KycRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($locked->status !== KycStatus::Submitted) {
                throw ValidationException::withMessages(['reason' => __('Only submitted documents can be reviewed.')]);
            }

            $locked->forceFill([
                'status' => $decision,
                'reviewed_by' => $staff->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $this->setStatus($subject, $decision);
            $this->log($staff, $locked, $decision === KycStatus::Approved ? 'approved' : 'rejected', ['reason' => $reason]);

            $request->setRawAttributes($locked->getAttributes(), true);
        });

        $this->alerts->kycReviewed($request, $this->subjectOf($request));

        return $request;
    }
}
