<?php

namespace App\Jobs;

use App\Actions\Referrals\RewardReferral;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Pays a referral bonus off the request that completed the order. Dispatched after commit. */
final class RewardReferralJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $buyerId) {}

    public function handle(RewardReferral $reward): void
    {
        $reward->handle($this->buyerId);
    }
}
