<?php

namespace App\Policies;

use App\Enums\AuctionStatus;
use App\Enums\UserRole;
use App\Models\Auction;
use App\Models\User;

/**
 * The whole team of a company prepares, submits and withdraws its auctions;
 * only the owner cancels one the platform has approved. Platform staff and
 * the system owner review them.
 */
class AuctionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->isSeller() || $user->isPlatform());
    }

    public function view(User $user, Auction $auction): bool
    {
        return $user->is_active && ($user->isPlatform() || $this->onTeam($user, $auction));
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->isSeller();
    }

    public function update(User $user, Auction $auction): bool
    {
        return $this->onTeam($user, $auction) && $auction->status->isEditable();
    }

    public function delete(User $user, Auction $auction): bool
    {
        return false;
    }

    /** Send for review, or take back from review. */
    public function submit(User $user, Auction $auction): bool
    {
        return $this->onTeam($user, $auction);
    }

    /**
     * Before it is approved anyone on the team may cancel; after, the owner
     * alone, and a live auction only while nobody has bid.
     */
    public function cancel(User $user, Auction $auction): bool
    {
        if (! $this->onTeam($user, $auction)) {
            return false;
        }

        return match ($auction->status) {
            AuctionStatus::Scheduled => $user->role === UserRole::CompanyOwner,
            AuctionStatus::Live => $user->role === UserRole::CompanyOwner && $auction->bid_count === 0,
            default => true,
        };
    }

    /** Bid, buy now and watch: active buyers with a confirmed email only. Sellers and staff never bid. */
    public function bid(User $user, Auction $auction): bool
    {
        return $user->is_active && $user->isBuyer() && $user->hasVerifiedEmail();
    }

    /** Approve, reject, or cancel an approved auction for the platform. */
    public function review(User $user, Auction $auction): bool
    {
        return $user->is_active && $user->isPlatform();
    }

    private function onTeam(User $user, Auction $auction): bool
    {
        return $user->is_active && $user->isSeller() && $user->company_id === $auction->company_id;
    }
}
