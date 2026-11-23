<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\LedgerEntryResource;
use App\Http\Resources\V1\WalletResource;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Wallet
 *
 * Balances and statement of your wallet (a buyer's own, or the company's for seller accounts).
 * Adding and withdrawing money is not available here: deposits go through a payment page and
 * withdrawals need a fresh emailed code, so both stay on the website.
 *
 * @authenticated
 */
class WalletController extends ApiController
{
    /**
     * Wallet balances
     *
     * `available` is balance minus held; `biddable` is the share of it that can go into bids.
     */
    public function show(Request $request): WalletResource
    {
        return new WalletResource($this->wallet($request));
    }

    /**
     * Statement
     *
     * Newest first. Amounts are signed: positive adds to the wallet, negative takes from it.
     *
     * @queryParam page integer Page number. Example: 1
     */
    public function statement(Request $request): AnonymousResourceCollection
    {
        $entries = LedgerEntry::query()
            ->where('wallet_id', $this->wallet($request)->id)
            ->with('transaction')
            ->latest('id')
            ->paginate($this->perPage($request));

        return LedgerEntryResource::collection($entries);
    }

    private function wallet(Request $request): Wallet
    {
        $user = $this->user($request);

        return Wallet::query()
            ->when(
                $user->company_id !== null,
                fn ($query) => $query->where('company_id', $user->company_id),
                fn ($query) => $query->where('user_id', $user->id),
            )
            ->firstOrFail();
    }
}
