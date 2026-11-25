<?php

namespace App\Actions\Trust;

use App\Actions\Companies\Concerns\LogsAdminActions;
use App\Enums\FraudFlagStatus;
use App\Enums\StrikeSource;
use App\Models\FraudFlag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Staff decide an open flag: dismiss it, or confirm it (and, if they say
 * so, give the bidder a strike). Cancelling the auction or suspending the
 * company is done with their own actions.
 */
final class ReviewFraudFlag
{
    use LogsAdminActions;

    public function __construct(private readonly IssueStrike $strikes) {}

    public function dismiss(User $staff, FraudFlag $flag, ?string $note = null): FraudFlag
    {
        return $this->decide($staff, $flag, FraudFlagStatus::Dismissed, $note, false);
    }

    public function confirm(User $staff, FraudFlag $flag, string $note, bool $strike = false): FraudFlag
    {
        if (mb_strlen(trim($note)) < 3) {
            throw ValidationException::withMessages(['note' => __('Say what you found, in a few words.')]);
        }

        return $this->decide($staff, $flag, FraudFlagStatus::Confirmed, $note, $strike);
    }

    private function decide(User $staff, FraudFlag $flag, FraudFlagStatus $status, ?string $note, bool $strike): FraudFlag
    {
        Gate::forUser($staff)->authorize('review', $flag);

        $note = $note === null ? null : mb_substr(trim($note), 0, 500);

        return DB::transaction(function () use ($staff, $flag, $status, $note, $strike): FraudFlag {
            $locked = FraudFlag::query()->lockForUpdate()->findOrFail($flag->id);

            if ($locked->status !== FraudFlagStatus::Open) {
                throw ValidationException::withMessages(['note' => __('This flag has already been decided.')]);
            }

            $locked->forceFill([
                'status' => $status,
                'reviewed_by' => $staff->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $this->record($staff, $locked, $status->value, ['note' => $note, 'strike' => $strike ?: null]);

            if ($strike) {
                $bidder = User::query()->withTrashed()->findOrFail($locked->user_id);
                $this->strikes->handle($staff, $bidder, (string) $note, $locked, StrikeSource::FraudFlag);
            }

            $flag->setRawAttributes($locked->getAttributes(), true);

            return $flag;
        });
    }
}
