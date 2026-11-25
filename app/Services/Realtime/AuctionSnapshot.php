<?php

namespace App\Services\Realtime;

use App\Models\Auction;
use App\Models\Bid;

/**
 * The public state of an auction, as the page renders it and as every
 * broadcast carries it. Each broadcast is a whole snapshot read when it is
 * sent, so a client that applies the one with the highest sequence number
 * is always up to date, whatever order they arrive in.
 *
 * Never carries a user id, a maximum or the reserve amount: bidders appear
 * as their masked name and a per-auction reference (see ref()).
 */
final class AuctionSnapshot
{
    /** @return array<string, mixed> */
    public static function of(Auction $auction, bool $withBids = true, ?int $viewers = null): array
    {
        $price = $auction->final_price ?? $auction->current_price;
        $next = $auction->minimumNextBid();

        $snapshot = [
            'id' => $auction->id,
            'status' => $auction->status->value,
            'final' => $auction->status->isFinal(),
            // Bids are numbered 1, 2, 3, … per auction; a final state outranks the same count.
            'seq' => $auction->bid_count * 2 + ($auction->status->isFinal() ? 1 : 0),
            'price' => $price->format(),
            'bid_count' => $auction->bid_count,
            'next_minimum' => $next->format(),
            'next_minimum_input' => $next->toDecimal(),
            'reserve_met' => $auction->reserve_price === null ? null : (bool) $auction->reserve_met,
            'buy_now' => $auction->buy_now_price !== null && $auction->bid_count === 0 && ! $auction->status->isFinal(),
            'starts_at' => $auction->starts_at?->getTimestampMs(),
            'ends_at' => $auction->ends_at?->getTimestampMs(),
            'extensions' => $auction->extension_count,
            'leader_ref' => $auction->leader_id === null ? null : self::ref($auction->id, $auction->leader_id),
            'viewers' => $viewers,
            'server_time' => now()->getTimestampMs(),
        ];

        if ($withBids) {
            $snapshot['bids'] = $auction->bids()
                ->standing()
                ->with(['bidder' => fn ($query) => $query->withTrashed()->select(['id', 'name'])])
                ->orderByDesc('seq')
                ->limit((int) config('auction.realtime.feed_size'))
                ->get()
                ->map(fn (Bid $bid): array => [
                    'seq' => $bid->seq,
                    'bidder' => $bid->bidder->maskedName(),
                    'ref' => self::ref($auction->id, $bid->user_id),
                    'amount' => $bid->amount->format(),
                    'at' => $bid->created_at?->getTimestampMs(),
                ])
                ->all();
        }

        return $snapshot;
    }

    /**
     * The part of the snapshot a card shows, built from the row alone (no
     * queries, so a grid of cards stays one query).
     *
     * @return array<string, mixed>
     */
    public static function card(Auction $auction): array
    {
        return [
            'id' => $auction->id,
            'status' => $auction->status->value,
            'final' => $auction->status->isFinal(),
            'seq' => $auction->bid_count * 2 + ($auction->status->isFinal() ? 1 : 0),
            'price' => ($auction->final_price ?? $auction->current_price)->format(),
            'bid_count' => $auction->bid_count,
            'ends_at' => $auction->ends_at?->getTimestampMs(),
        ];
    }

    /**
     * Stands for one bidder on one auction. Lets a page tell its own bids
     * from others' without a user id; it cannot be traced back without the
     * application key, and differs from one auction to the next.
     */
    public static function ref(int $auctionId, int $userId): string
    {
        return substr(hash_hmac('sha256', "bidder:{$auctionId}:{$userId}", (string) config('app.key')), 0, 16);
    }
}
