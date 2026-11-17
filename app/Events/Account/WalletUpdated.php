<?php

namespace App\Events\Account;

use App\Models\Wallet;

/**
 * A buyer's balance or held amount changed. The wallet is read again when
 * the broadcast is sent, so the figures are the latest ones.
 */
final class WalletUpdated extends UserBroadcast
{
    public function __construct(public Wallet $wallet) {}

    protected function userId(): int
    {
        return (int) $this->wallet->user_id;
    }

    public function broadcastAs(): string
    {
        return 'wallet.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'available' => $this->wallet->available()->format(),
            'balance' => $this->wallet->balance->format(),
            'held' => $this->wallet->held->format(),
            // Changes to one wallet are made under its row lock, so this orders them.
            'version' => $this->wallet->updated_at?->getTimestampMs() ?? 0,
        ];
    }
}
