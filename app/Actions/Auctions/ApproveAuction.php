<?php

namespace App\Actions\Auctions;

use App\Actions\Auctions\Concerns\AuctionRules;
use App\Enums\AuctionStatus;
use App\Enums\NotificationKind;
use App\Models\Auction;
use App\Models\Company;
use App\Models\User;
use App\Services\Fees\FeeResolver;
use App\Services\Notifications\AccountAlerts;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Platform staff approve an auction under review with the real schedule
 * (the seller's proposal, or times the reviewer chose). Approval fixes the
 * anti-sniping settings and the sale commission on the auction, so later
 * changes to settings or the company's agreement do not affect it.
 */
final class ApproveAuction
{
    use AuctionRules;

    public function __construct(private readonly FeeResolver $fees) {}

    public function handle(User $reviewer, Auction $auction, CarbonInterface|string|null $startsAt, CarbonInterface|string|null $endsAt): Auction
    {
        Gate::forUser($reviewer)->authorize('review', $auction);

        $this->checkSchedule($startsAt, $endsAt, 'starts_at', 'ends_at', allowNow: true);

        return DB::transaction(function () use ($reviewer, $auction, $startsAt, $endsAt): Auction {
            // Company first, then the auction: the order SubmitAuction uses too.
            $company = Company::query()->lockForUpdate()->findOrFail($auction->company_id);
            $locked = Auction::query()->lockForUpdate()->findOrFail($auction->id);

            if ($locked->status !== AuctionStatus::PendingReview) {
                throw ValidationException::withMessages(['auction' => __('Only an auction awaiting review can be approved.')]);
            }

            $this->checkCompanyMayList($company);

            $this->schedule($locked, $company, $this->toDate($startsAt), $this->toDate($endsAt), $reviewer);
            $this->record($reviewer, $locked, 'approved', [
                'starts_at' => $locked->starts_at?->toIso8601String(),
                'ends_at' => $locked->ends_at?->toIso8601String(),
            ]);

            app(AccountAlerts::class)->listing($locked, NotificationKind::AuctionApproved, __('Your auction is approved and scheduled.'));

            return $auction->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Moves a locked auction under review to scheduled. A start that has just
     * passed becomes now, keeping the end. Used by SubmitAuction for
     * companies the system owner marked as auto-approved ($reviewer null).
     */
    public function schedule(Auction $auction, Company $company, ?CarbonInterface $startsAt, ?CarbonInterface $endsAt, ?User $reviewer): void
    {
        if ($startsAt === null || $endsAt === null) {
            throw ValidationException::withMessages(['starts_at' => __('Choose when the auction starts and ends.')]);
        }

        $starts = $startsAt->lessThan(now()) ? now() : $startsAt;

        $auction->transitionTo(AuctionStatus::Scheduled);
        $auction->forceFill([
            'starts_at' => $starts,
            'ends_at' => $endsAt,
            'original_ends_at' => $endsAt,
            'extension_count' => 0,
            'anti_snipe_window_seconds' => (int) config('auction.bidding.anti_snipe_window_seconds'),
            'anti_snipe_extend_seconds' => (int) config('auction.bidding.anti_snipe_extend_seconds'),
            'anti_snipe_max_extensions' => (int) config('auction.bidding.anti_snipe_max_extensions'),
            'fee_snapshot' => ['sale_commission' => $this->fees->saleCommission($company)->toSnapshot()],
            'reviewed_by' => $reviewer?->id,
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ])->save();
    }
}
