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
 * Creates a buyer account with its wallet.
 *
 * The caller validates the input and fires the Registered event, which
 * emails the verification code.
 */
final class RegisterBuyer
{
    /**
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function handle(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            // A new User is a buyer by default; role cannot be mass-assigned.
            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            $user->kyc_status = Kyc::startingStatus();
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
