<?php

namespace App\Actions\Auctions\Concerns;

use App\Enums\AuctionStatus;
use App\Enums\CompanyStatus;
use App\Models\Auction;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

/** Checks shared by the auction actions, and the `auctions` activity log. */
trait AuctionRules
{
    /**
     * A start in the future (within the lead time) and a duration within the
     * allowed range. With $allowNow a start up to a minute ago is accepted
     * (the reviewer chose "now"); the caller moves it to the present.
     */
    private function checkSchedule(mixed $startsAt, mixed $endsAt, string $startKey, string $endKey, bool $allowNow = false): void
    {
        $starts = $this->toDate($startsAt);
        $ends = $this->toDate($endsAt);

        if ($starts === null) {
            throw ValidationException::withMessages([$startKey => __('Choose when the auction starts.')]);
        }

        if ($ends === null) {
            throw ValidationException::withMessages([$endKey => __('Choose when the auction ends.')]);
        }

        if ($starts->lessThan($allowNow ? now()->subMinute() : now())) {
            throw ValidationException::withMessages([$startKey => __('The start time has passed. Choose a later one.')]);
        }

        if ($starts->greaterThan(now()->addDays((int) config('auction.listing.max_lead_days')))) {
            throw ValidationException::withMessages([$startKey => __('An auction can start at most :days days from now.', ['days' => config('auction.listing.max_lead_days')])]);
        }

        $minutes = $starts->diffInMinutes($ends, false);
        $min = (int) config('auction.listing.min_duration_minutes');
        $maxDays = (int) config('auction.listing.max_duration_days');

        if ($minutes < $min) {
            throw ValidationException::withMessages([$endKey => trans_choice('An auction must run for at least :count minute.|An auction must run for at least :count minutes.', $min)]);
        }

        if ($minutes > $maxDays * 24 * 60) {
            throw ValidationException::withMessages([$endKey => trans_choice('An auction can run for at most :count day.|An auction can run for at most :count days.', $maxDays)]);
        }
    }

    /** The company may send auctions for review and have them approved. */
    private function checkCompanyMayList(Company $company): void
    {
        $company->loadMissing('subscription.plan');

        $message = match (true) {
            $company->status === CompanyStatus::Suspended => __('The company is suspended and cannot list auctions.'),
            $company->status === CompanyStatus::Pending => __('The platform has not verified the company yet. You can prepare drafts in the meantime.'),
            ! ($company->subscription?->allowsSelling() ?? false) => __('The company’s plan does not allow selling right now. See Plan & billing.'),
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages(['auction' => $message]);
        }
    }

    /** The plan's cap on auctions under review, scheduled or live. */
    private function checkActiveLimit(Company $company, Auction $auction): void
    {
        $limit = $company->subscription?->maxActiveAuctions();

        if ($limit === null) {
            return;
        }

        $active = $company->auctions()
            ->whereIn('status', AuctionStatus::active())
            ->whereKeyNot($auction->id)
            ->count();

        if ($active >= $limit) {
            throw ValidationException::withMessages(['auction' => trans_choice(
                'Your plan allows :count active auction (under review, scheduled or live). Wait for one to finish or change plan.|Your plan allows :count active auctions (under review, scheduled or live). Wait for one to finish or change plan.',
                $limit,
            )]);
        }
    }

    private function toDate(mixed $value): ?CarbonInterface
    {
        return match (true) {
            $value instanceof CarbonInterface => $value,
            is_string($value) && $value !== '' => Date::parse($value, 'UTC'),
            default => null,
        };
    }

    /** @param  array<string, mixed>  $properties */
    private function record(?User $by, Auction $auction, string $event, array $properties = []): void
    {
        activity('auctions')
            ->causedBy($by)
            ->performedOn($auction)
            ->event($event)
            ->withProperties(array_filter($properties, fn (mixed $value) => $value !== null))
            ->log("{$event} auction {$auction->id}");
    }
}
