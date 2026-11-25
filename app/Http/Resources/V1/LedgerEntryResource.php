<?php

namespace App\Http\Resources\V1;

use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a wallet statement. Load transaction first.
 *
 * @mixin LedgerEntry
 */
class LedgerEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->transaction->type->value,
            'description' => $this->transaction->description,
            // Signed: positive adds to the wallet, negative takes from it.
            'amount' => $this->amount,
            'balance_after' => $this->balance_after,
            'at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
