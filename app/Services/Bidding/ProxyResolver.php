<?php

namespace App\Services\Bidding;

use App\Enums\BidType;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Works out what one new bid does to an auction. Pure: no database, no
 * clock, so every case can be tested from a table.
 *
 * Every bidder has a maximum: a manual bid's maximum is its amount, an
 * automatic bid's is the most its owner will pay. The higher maximum leads,
 * at one increment above the other maximum (never above its own); on equal
 * maximums the earlier bidder, the current leader, keeps the lead. When the
 * leading maximum covers the reserve, the price goes straight to the reserve.
 * The reserve amount is never revealed, only whether it is met.
 */
final class ProxyResolver
{
    public function resolve(BidState $state, int $bidderId, Money $max, bool $auto): Resolution
    {
        // The leader only has to beat their own maximum, not the next minimum.
        if ($state->leaderId === $bidderId) {
            return $this->raise($state, $max, $auto);
        }

        if ($max->lessThan($state->minimumNext())) {
            throw new InvalidArgumentException('The bid is below the minimum next bid.');
        }

        if ($state->leaderId === null) {
            $price = $auto ? $this->reserveJump($state, $state->starting, $max) : $max;

            return $this->result($state, [new ResolvedBid($bidderId, $price, $this->type($auto))], $price, $bidderId, $max, $auto);
        }

        $leaderMax = $state->leaderMax();

        // The challenger's maximum is higher: the leader's automatic bid
        // defends up to its maximum, then the challenger leads.
        if ($max->greaterThan($leaderMax)) {
            $rows = [];

            if ($state->leaderAuto && $leaderMax->greaterThan($state->price)) {
                $rows[] = new ResolvedBid($state->leaderId, $leaderMax, BidType::Proxy);
            }

            $price = $auto
                ? $this->reserveJump($state, $this->atMost($leaderMax->plus($state->increment), $max), $max)
                : $max;

            $rows[] = new ResolvedBid($bidderId, $price, $this->type($auto));

            return $this->result($state, $rows, $price, $bidderId, $max, $auto);
        }

        // The leader's automatic maximum is at least as high: the challenger's
        // bid is recorded, and the leader answers one increment above it.
        $price = $this->reserveJump($state, $this->atMost($max->plus($state->increment), $leaderMax), $leaderMax);

        return $this->result($state, [
            new ResolvedBid($bidderId, $max, $this->type($auto)),
            new ResolvedBid($state->leaderId, $price, BidType::Proxy),
        ], $price, $state->leaderId, $leaderMax, true);
    }

    /** The leader raises their own maximum. Only an automatic bid can do that. */
    private function raise(BidState $state, Money $max, bool $auto): Resolution
    {
        if (! $auto || ! $max->greaterThan($state->leaderMax())) {
            throw new InvalidArgumentException('The leader can only raise their maximum with a higher automatic bid.');
        }

        $price = $this->reserveJump($state, $state->price, $max);
        $rows = $price->greaterThan($state->price) ? [new ResolvedBid($state->leaderId ?? 0, $price, BidType::Proxy)] : [];

        return $this->result($state, $rows, $price, $state->leaderId ?? 0, $max, true);
    }

    /** A price for a maximum that covers the reserve is lifted to the reserve. */
    private function reserveJump(BidState $state, Money $price, Money $max): Money
    {
        $reserve = $state->reserve;

        return $reserve !== null && $price->lessThan($reserve) && $max->greaterThanOrEqual($reserve) ? $reserve : $price;
    }

    private function atMost(Money $amount, Money $cap): Money
    {
        return $amount->greaterThan($cap) ? $cap : $amount;
    }

    private function type(bool $auto): BidType
    {
        return $auto ? BidType::Proxy : BidType::Manual;
    }

    /** @param  list<ResolvedBid>  $rows */
    private function result(BidState $state, array $rows, Money $price, int $leaderId, Money $leaderMax, bool $leaderAuto): Resolution
    {
        return new Resolution(
            bids: $rows,
            price: $price,
            leaderId: $leaderId,
            leaderMax: $leaderMax,
            leaderAuto: $leaderAuto,
            reserveMet: $state->reserve === null || $price->greaterThanOrEqual($state->reserve),
        );
    }
}
