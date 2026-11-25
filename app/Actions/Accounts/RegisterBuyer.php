<?php

namespace App\Actions\Accounts;

use App\Enums\AccountKind;
use App\Enums\WalletType;
use App\Models\User;
use App\Models\UserType;
use App\Models\Wallet;
use App\Support\Kyc;
use Illuminate\Support\Facades\DB;

/**
 * Creates a buyer account with its wallet, remembering who referred them
 * (RewardReferral pays both once their first order completes).
 *
 * The caller validates the input and fires the Registered event, which
 * emails the verification code.
 */
final class RegisterBuyer
{
    /**
     * $referrer: the buyer whose referral code brought them, if any.
     *
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function handle(array $data, ?User $referrer = null): User
    {
        return DB::transaction(function () use ($data, $referrer): User {
            // A new User is a buyer by default; role cannot be mass-assigned.
            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            $user->kyc_status = Kyc::startingStatus();
            $user->referred_by_id = $referrer !== null && $referrer->isBuyer() && $referrer->is_active ? $referrer->id : null;
            $user->user_type_id = UserType::query()
                ->where('account_kind', AccountKind::Buyer)
                ->where('is_default', true)
                ->where('is_active', true)
                ->value('id');
            $user->save();

            Wallet::query()->create([
                'type' => WalletType::Buyer,
                'user_id' => $user->id,
                'currency' => config('auction.currency'),
            ]);

            return $user;
        });
    }
}
