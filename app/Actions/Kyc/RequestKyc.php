<?php

namespace App\Actions\Kyc;

use App\Actions\Kyc\Concerns\ChangesKyc;
use App\Enums\KycStatus;
use App\Models\Company;
use App\Models\KycRequest;
use App\Models\User;
use App\Services\Notifications\AccountAlerts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Platform staff ask a buyer or a company for (new) identity documents.
 * Until they are approved the account cannot bid, list or withdraw.
 */
final class RequestKyc
{
    use ChangesKyc;

    public function __construct(private readonly AccountAlerts $alerts) {}

    public function handle(User $staff, User|Company $subject, ?string $note = null): KycRequest
    {
        Gate::forUser($staff)->authorize('review', KycRequest::class);

        if ($subject instanceof User && ! $subject->isBuyer()) {
            throw ValidationException::withMessages(['note' => __('Only buyers and companies are verified.')]);
        }

        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 2000) : null;

        $request = DB::transaction(function () use ($staff, $subject, $note): KycRequest {
            $locked = $this->lockSubject($subject);

            if (in_array($locked->kyc_status, [KycStatus::Requested, KycStatus::Submitted], true)) {
                throw ValidationException::withMessages(['note' => __('Documents have already been asked for.')]);
            }

            $request = new KycRequest(['note' => $note]);
            $request->forceFill([
                'user_id' => $locked instanceof User ? $locked->id : null,
                'company_id' => $locked instanceof Company ? $locked->id : null,
                'status' => KycStatus::Requested,
                'requested_by' => $staff->id,
            ])->save();

            $this->setStatus($locked, KycStatus::Requested);
            $this->log($staff, $request, 'requested', ['note' => $note]);

            return $request;
        });

        $subject->refresh();
        $this->alerts->kycRequested($subject, $note);

        return $request;
    }
}
