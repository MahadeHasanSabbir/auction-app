<?php

namespace App\Actions\Accounts;

use App\Models\Bid;
use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\KycRequest;
use App\Models\LedgerEntry;
use App\Models\LoginEvent;
use App\Models\Message;
use App\Models\Order;
use App\Models\Review;
use App\Models\SavedSearch;
use App\Models\Strike;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything the marketplace keeps about a buyer, as one JSON document they
 * can download from their security page. Other people's details (the seller's
 * commission, other bidders) are left out; internal investigations (fraud
 * flags) are not part of it.
 */
final class ExportAccountData
{
    /** @return array<string, mixed> */
    public function handle(User $user): array
    {
        $wallet = Wallet::query()->where('user_id', $user->id)->first();

        return [
            'exported_at' => now()->toIso8601String(),
            'account' => $user->only([
                'id', 'name', 'email', 'phone', 'address', 'locale', 'timezone', 'role', 'kyc_status',
                'email_verified_at', 'last_login_at', 'referral_code', 'notification_preferences', 'created_at',
            ]),
            'wallet' => $wallet?->only(['balance', 'held', 'bonus', 'currency', 'created_at']),
            'statement' => $wallet === null ? [] : $this->rows(
                LedgerEntry::query()->where('wallet_id', $wallet->id)->with('transaction:id,type,description,created_at')->orderBy('id')->get(),
                fn (LedgerEntry $e) => [
                    'date' => $e->transaction?->created_at,
                    'type' => $e->transaction?->type,
                    'description' => $e->transaction?->description,
                    'amount' => $e->amount,
                    'bonus' => $e->bonus,
                    'balance_after' => $e->balance_after,
                ],
            ),
            'deposits' => $this->rows(Deposit::query()->where('user_id', $user->id)->orderBy('id')->get(), fn (Deposit $d) => $d->only([
                'tran_id', 'gateway', 'amount', 'gateway_charge', 'service_charge', 'net_amount', 'status', 'failure_reason', 'created_at', 'completed_at',
            ])),
            'withdrawals' => $wallet === null ? [] : $this->rows(WithdrawalRequest::query()->where('wallet_id', $wallet->id)->orderBy('id')->get(), fn (WithdrawalRequest $w) => $w->only([
                'amount', 'gateway_charge', 'service_charge', 'net_amount', 'method', 'destination', 'status', 'rejection_reason', 'created_at', 'paid_at',
            ])),
            'bids' => $this->rows(Bid::query()->where('user_id', $user->id)->with('auction:id,title')->orderBy('id')->get(), fn (Bid $b) => [
                'auction' => $b->auction?->title,
                'auction_id' => $b->auction_id,
                'amount' => $b->amount,
                'type' => $b->type,
                'ip_address' => $b->ip_address,
                'retracted_at' => $b->retracted_at,
                'placed_at' => $b->created_at,
            ]),
            'watchlist' => DB::table('watchlists')->join('auctions', 'auctions.id', '=', 'watchlists.auction_id')
                ->where('watchlists.user_id', $user->id)->get(['auctions.id as auction_id', 'auctions.title', 'watchlists.created_at'])->all(),
            'orders' => $this->rows(Order::query()->where('buyer_id', $user->id)->orderBy('id')->get(), fn (Order $o) => $o->only([
                'number', 'status', 'amount', 'discount', 'refunded_amount', 'shipping_name', 'shipping_phone', 'shipping_address', 'created_at', 'completed_at',
            ])),
            'reviews' => $this->rows(Review::query()->where('buyer_id', $user->id)->orderBy('id')->get(), fn (Review $r) => $r->only(['order_id', 'rating', 'comment', 'created_at'])),
            'messages' => $this->rows(Message::query()->where('user_id', $user->id)->orderBy('id')->get(), fn (Message $m) => $m->only(['conversation_id', 'body', 'created_at'])),
            'invoices' => $this->rows(Invoice::query()->where('user_id', $user->id)->orderBy('id')->get(), fn (Invoice $i) => $i->only(['number', 'kind', 'total', 'issued_at'])),
            'saved_searches' => $this->rows(SavedSearch::query()->where('user_id', $user->id)->orderBy('id')->get(), fn (SavedSearch $s) => $s->only(['name', 'filters', 'alerts', 'created_at'])),
            'identity_checks' => $this->rows(KycRequest::query()->where('user_id', $user->id)->withCount('documents')->orderBy('id')->get(), fn (KycRequest $k) => $k->only([
                'status', 'rejection_reason', 'documents_count', 'submitted_at', 'reviewed_at',
            ])),
            'strikes' => $this->rows(Strike::query()->where('user_id', $user->id)->orderBy('id')->get(), fn (Strike $s) => $s->only(['source', 'reason', 'created_at', 'expires_at', 'revoked_at'])),
            'sign_ins' => $this->rows(LoginEvent::query()->where('user_id', $user->id)->orderBy('id')->get(), fn (LoginEvent $l) => $l->only([
                'method', 'succeeded', 'ip_address', 'user_agent', 'created_at',
            ])),
            'notifications' => $user->notifications()->orderBy('created_at')->get(['data', 'read_at', 'created_at'])->toArray(),
            'api_tokens' => $user->tokens()->orderBy('id')->get(['name', 'abilities', 'last_used_at', 'expires_at', 'created_at'])->toArray(),
        ];
    }

    /**
     * @template T of Model
     *
     * @param  Collection<int, T>  $rows
     * @param  callable(T): array<string, mixed>  $map
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $rows, callable $map): array
    {
        return $rows->map($map)->values()->all();
    }
}
