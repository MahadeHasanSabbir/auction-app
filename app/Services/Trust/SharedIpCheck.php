<?php

namespace App\Services\Trust;

use App\Enums\FraudFlagKind;
use App\Enums\FraudFlagStatus;
use App\Enums\UserRole;
use App\Models\Auction;
use App\Models\FraudFlag;
use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Flags a bidder who bid from an IP address that someone on the selling
 * company's team signed in from recently (shill bidding). Only flags: staff
 * decide. One flag per auction and bidder; later matches add to it.
 */
final class SharedIpCheck
{
    public function check(Auction $auction, User $bidder, string $ip): ?FraudFlag
    {
        $team = User::query()->withTrashed()
            ->where('company_id', $auction->company_id)
            ->whereIn('role', [UserRole::CompanyOwner, UserRole::CompanyMember])
            ->pluck('id');

        if ($team->isEmpty()) {
            return null;
        }

        $matches = LoginEvent::query()
            ->whereIn('user_id', $team)
            ->where('ip_address', $ip)
            ->where('succeeded', true)
            ->where('created_at', '>=', now()->subDays((int) config('auction.trust.shared_ip_days')))
            ->distinct()
            ->pluck('user_id')
            ->all();

        if ($matches === []) {
            return null;
        }

        $flag = FraudFlag::query()
            ->where('kind', FraudFlagKind::SharedIp)
            ->where('auction_id', $auction->id)
            ->where('user_id', $bidder->id)
            ->first();

        $details = ['team_user_ids' => $matches];

        if ($flag !== null) {
            $flag->forceFill(['details' => $details, 'ip_address' => $ip])->save();

            return $flag;
        }

        try {
            $flag = new FraudFlag;
            $flag->forceFill([
                'kind' => FraudFlagKind::SharedIp,
                'user_id' => $bidder->id,
                'auction_id' => $auction->id,
                'company_id' => $auction->company_id,
                'ip_address' => $ip,
                'details' => $details,
                'status' => FraudFlagStatus::Open,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // Two bids checked at once: the other one wrote the flag.
            return null;
        }

        return $flag;
    }
}
