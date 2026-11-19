<?php

namespace App\Actions\Trust;

use App\Actions\Accounts\SuspendAccount;
use App\Enums\StrikeSource;
use App\Models\Strike;
use App\Models\User;
use App\Services\Notifications\AccountAlerts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A strike against a buyer: by platform staff (handle) or by the system for
 * retracted bids, an order cancelled for want of an address, or a confirmed
 * flag (automatic). It counts for auction.trust.strike_days; reaching
 * auction.trust.strike_limit active strikes suspends the account at once.
 *
 * Locks the buyer's row, so two strikes at once count each other. Runs in
 * the caller's transaction when there is one.
 */
final class IssueStrike
{
    public function __construct(private readonly SuspendAccount $suspend) {}

    public function handle(User $staff, User $buyer, string $reason, ?Model $reference = null, StrikeSource $source = StrikeSource::Manual): Strike
    {
        Gate::forUser($staff)->authorize('issue', [Strike::class, $buyer]);

        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('Give the reason in a few words.')]);
        }

        return $this->give($buyer, $source, $reason, $reference, $staff);
    }

    public function automatic(User $buyer, StrikeSource $source, string $reason, ?Model $reference = null): ?Strike
    {
        return $buyer->isBuyer() ? $this->give($buyer, $source, $reason, $reference, null) : null;
    }

    private function give(User $buyer, StrikeSource $source, string $reason, ?Model $reference, ?User $by): Strike
    {
        return DB::transaction(function () use ($buyer, $source, $reason, $reference, $by): Strike {
            $locked = User::query()->lockForUpdate()->findOrFail($buyer->id);

            $strike = new Strike;
            $strike->forceFill([
                'user_id' => $locked->id,
                'source' => $source,
                'reason' => mb_substr($reason, 0, 500),
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'issued_by' => $by?->id,
                'expires_at' => now()->addDays((int) config('auction.trust.strike_days')),
            ])->save();

            $active = Strike::query()->where('user_id', $locked->id)->active()->count();
            $limit = (int) config('auction.trust.strike_limit');

            $this->log($by, $locked, $strike, $active);
            app(AccountAlerts::class)->strike($locked, $strike, $active, $limit);

            if ($locked->is_active && $active >= $limit) {
                $this->suspend->automatically($locked, __(':count active strikes; the latest: :reason', ['count' => $active, 'reason' => $strike->reason]));
                $buyer->is_active = false;
            }

            return $strike;
        });
    }

    private function log(?User $by, User $buyer, Strike $strike, int $active): void
    {
        activity('admin')
            ->causedBy($by)
            ->performedOn($buyer)
            ->event('strike')
            ->withProperties(['strike_id' => $strike->id, 'source' => $strike->source->value, 'reason' => $strike->reason, 'active' => $active])
            ->log("strike User {$buyer->id}");
    }
}
