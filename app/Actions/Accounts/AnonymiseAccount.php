<?php

namespace App\Actions\Accounts;

use App\Actions\Companies\Concerns\LogsAdminActions;
use App\Enums\OrderStatus;
use App\Models\KycDocument;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ledger\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Platform staff wipe a buyer's personal details when the person asks for
 * their data to be deleted. The account is closed for good; the name, email,
 * phone, address, picture, identity documents, sign-in history, devices,
 * tokens and saved searches go. Bids, orders, ledger rows, invoices and
 * messages stay (other people and the accounts depend on them), now pointing
 * at "Deleted user #id". Only once the wallet is empty and no order is open.
 */
final class AnonymiseAccount
{
    use LogsAdminActions;

    public function __construct(private readonly Ledger $ledger) {}

    public function handle(User $by, User $account, string $reason): User
    {
        Gate::forUser($by)->authorize('anonymise', $account);

        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('Give the reason in a few words.')]);
        }

        $files = [];

        DB::transaction(function () use ($by, $account, $reason, &$files): void {
            $account = User::withTrashed()->whereKey($account->id)->lockForUpdate()->firstOrFail();

            if ($account->anonymised_at !== null) {
                throw ValidationException::withMessages(['reason' => __('This account has already been anonymised.')]);
            }

            $walletId = Wallet::query()->where('user_id', $account->id)->value('id');

            if ($walletId !== null) {
                $wallet = $this->ledger->lock($walletId)[$walletId];

                if (! $wallet->balance->isZero() || ! $wallet->held->isZero()) {
                    throw ValidationException::withMessages(['reason' => __('The wallet still holds money. It must be withdrawn first.')]);
                }
            }

            $open = Order::query()->where('buyer_id', $account->id)
                ->whereIn('status', array_filter(OrderStatus::cases(), fn (OrderStatus $s) => $s->holdsEscrow()))
                ->exists();

            if ($open) {
                throw ValidationException::withMessages(['reason' => __('The buyer has an open order. Wait until it is finished.')]);
            }

            $files = ['public' => array_filter([$account->avatar_path])];
            $documents = KycDocument::query()->whereHas('request', fn ($q) => $q->where('user_id', $account->id));
            $files['kyc'] = $documents->pluck('path')->all();
            $documents->delete();

            $account->forceFill([
                'name' => __('Deleted user #:id', ['id' => $account->id]),
                'email' => "deleted-{$account->id}@anonymised.invalid",
                'password' => Hash::make(Str::random(40)),
                'phone' => null,
                'address' => null,
                'avatar_path' => null,
                'timezone' => null,
                'notification_preferences' => null,
                'is_active' => false,
                'suspension_reason' => null,
                'remember_token' => null,
                'anonymised_at' => now(),
            ])->save();

            if (! $account->trashed()) {
                $account->delete();
            }

            // Delivery details on finished orders.
            Order::query()->where('buyer_id', $account->id)->update(['shipping_phone' => null, 'shipping_address' => null]);

            foreach (['trusted_devices', 'social_accounts', 'otp_codes', 'login_events', 'saved_searches', 'watchlists', 'push_subscriptions'] as $table) {
                DB::table($table)->where($table === 'push_subscriptions' ? 'subscribable_id' : 'user_id', $account->id)
                    ->when($table === 'push_subscriptions', fn ($q) => $q->where('subscribable_type', $account->getMorphClass()))
                    ->delete();
            }

            $account->tokens()->delete();
            $account->notifications()->delete();
            DB::table('sessions')->where('user_id', $account->id)->delete();

            $this->record($by, $account, 'anonymised', ['reason' => $reason]);
        });

        // Files go only once the rows are gone for good.
        foreach ($files as $disk => $paths) {
            if ($paths !== []) {
                Storage::disk($disk)->delete($paths);
            }
        }

        return $account->refresh();
    }
}
