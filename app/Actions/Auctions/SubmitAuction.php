<?php

namespace App\Actions\Auctions;

use App\Actions\Auctions\Concerns\AuctionRules;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Sends a draft for platform review. The company must be verified, on a
 * plan that allows selling, and within the plan's active-auction allowance
 * (counted under the company row lock, so two submissions cannot both take
 * the last place). An auto-approved company's auction is scheduled at once
 * with its proposed times.
 */
final class SubmitAuction
{
    use AuctionRules;

    public function __construct(private readonly ApproveAuction $approve) {}

    public function handle(User $user, Auction $auction): Auction
    {
        Gate::forUser($user)->authorize('submit', $auction);

        return DB::transaction(function () use ($user, $auction): Auction {
            $company = Company::query()->lockForUpdate()->findOrFail($auction->company_id);
            $locked = Auction::query()->lockForUpdate()->with('product')->findOrFail($auction->id);

            if ($locked->status !== AuctionStatus::Draft) {
                throw ValidationException::withMessages(['auction' => __('Only a draft can be sent for review.')]);
            }

            $this->checkCompanyMayList($company);

            if ($locked->product === null) {
                throw ValidationException::withMessages(['auction' => __('The product of this auction was deleted. Choose another one.')]);
            }

            if (! $locked->product->images()->exists()) {
                throw ValidationException::withMessages(['auction' => __('Add at least one picture to the product first.')]);
            }

            $this->checkSchedule($locked->proposed_starts_at, $locked->proposed_ends_at, 'proposed_starts_at', 'proposed_ends_at');
            $this->checkActiveLimit($company, $locked);

            $locked->transitionTo(AuctionStatus::PendingReview);
            $locked->forceFill([
                'submitted_at' => now(),
                'reviewed_by' => null,
                'reviewed_at' => null,
                'rejection_reason' => null,
            ])->save();

            $this->record($user, $locked, 'submitted');

            if ($company->auto_approve) {
                $this->approve->schedule($locked, $company, $locked->proposed_starts_at, $locked->proposed_ends_at, null);
                $this->record(null, $locked, 'auto-approved');
            }

            $locked->unsetRelation('product');

            return $auction->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
