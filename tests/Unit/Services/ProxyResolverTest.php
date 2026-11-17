<?php

namespace Tests\Unit\Services;

use App\Enums\BidType;
use App\Services\Bidding\BidState;
use App\Services\Bidding\ProxyResolver;
use App\Services\Bidding\ResolvedBid;
use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Proxy bidding, case by case. Amounts are whole currency units; the
 * auction starts at 100 with an increment of 10. Buyer 1 is the leader
 * where there is one, buyer 2 the new bidder.
 */
class ProxyResolverTest extends TestCase
{
    /**
     * @param  array{price: int, bids: int, leader: ?int, leaderAuto: ?int, reserve: ?int}  $state
     * @param  list<array{0: int, 1: int, 2: string}>  $rows  [user, amount, type]
     * @return array<string, mixed>
     */
    private static function case(array $state, int $bidder, int $max, bool $auto, array $rows, int $price, int $leader, int $leaderMax, bool $reserveMet = true): array
    {
        return compact('state', 'bidder', 'max', 'auto', 'rows', 'price', 'leader', 'leaderMax', 'reserveMet');
    }

    /** @return array<string, array<int, mixed>> */
    public static function cases(): array
    {
        $none = ['price' => 100, 'bids' => 0, 'leader' => null, 'leaderAuto' => null, 'reserve' => null];
        $manualLeader = ['price' => 150, 'bids' => 3, 'leader' => 1, 'leaderAuto' => null, 'reserve' => null];
        $autoLeader = ['price' => 150, 'bids' => 3, 'leader' => 1, 'leaderAuto' => 300, 'reserve' => null];

        return array_map(fn (array $case) => [$case], [
            'first manual bid at the starting price' => self::case($none, 2, 100, false, [[2, 100, 'manual']], 100, 2, 100),
            'first manual bid above the starting price' => self::case($none, 2, 140, false, [[2, 140, 'manual']], 140, 2, 140),
            'first automatic bid opens at the starting price' => self::case($none, 2, 500, true, [[2, 100, 'proxy']], 100, 2, 500),
            'first automatic bid jumps to a covered reserve' => self::case([...$none, 'reserve' => 400], 2, 500, true, [[2, 400, 'proxy']], 400, 2, 500),
            'first automatic bid below the reserve stays at the start' => self::case([...$none, 'reserve' => 600], 2, 500, true, [[2, 100, 'proxy']], 100, 2, 500, false),
            'manual bid beats a manual leader' => self::case($manualLeader, 2, 160, false, [[2, 160, 'manual']], 160, 2, 160),
            'automatic bid beats a manual leader by one increment' => self::case($manualLeader, 2, 900, true, [[2, 160, 'proxy']], 160, 2, 900),
            'lower manual bid is answered by the leader maximum' => self::case($autoLeader, 2, 200, false, [[2, 200, 'manual'], [1, 210, 'proxy']], 210, 1, 300),
            'answer never passes the leader maximum' => self::case($autoLeader, 2, 295, false, [[2, 295, 'manual'], [1, 300, 'proxy']], 300, 1, 300),
            'equal maximums: the earlier bidder keeps the lead' => self::case($autoLeader, 2, 300, true, [[2, 300, 'proxy'], [1, 300, 'proxy']], 300, 1, 300),
            'higher automatic bid wins one increment above the old maximum' => self::case($autoLeader, 2, 500, true, [[1, 300, 'proxy'], [2, 310, 'proxy']], 310, 2, 500),
            'a margin under one increment wins at its own maximum' => self::case($autoLeader, 2, 305, true, [[1, 300, 'proxy'], [2, 305, 'proxy']], 305, 2, 305),
            'manual bid over the old maximum wins at its amount' => self::case($autoLeader, 2, 350, false, [[1, 300, 'proxy'], [2, 350, 'manual']], 350, 2, 350),
            'new leader jumps to a covered reserve' => self::case([...$autoLeader, 'reserve' => 450], 2, 500, true, [[1, 300, 'proxy'], [2, 450, 'proxy']], 450, 2, 500),
            'defending leader jumps to a reserve it covers' => self::case([...$autoLeader, 'leaderAuto' => 800, 'reserve' => 450], 2, 200, false, [[2, 200, 'manual'], [1, 450, 'proxy']], 450, 1, 800),
            'reserve stays unmet when no maximum covers it' => self::case([...$autoLeader, 'reserve' => 1000], 2, 200, false, [[2, 200, 'manual'], [1, 210, 'proxy']], 210, 1, 300, false),
            'leader raises their maximum without a new bid' => self::case($autoLeader, 1, 600, true, [], 150, 1, 600),
            'leader raises their maximum by less than an increment over the price' => self::case([...$autoLeader, 'price' => 295, 'leaderAuto' => 300], 1, 302, true, [], 295, 1, 302),
            'leader raises their maximum past the reserve' => self::case([...$autoLeader, 'reserve' => 450], 1, 600, true, [[1, 450, 'proxy']], 450, 1, 600),
        ]);
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('cases')]
    public function test_it_resolves(array $case): void
    {
        $resolution = (new ProxyResolver)->resolve($this->state($case['state']), $case['bidder'], $this->money($case['max']), $case['auto']);

        $this->assertSame(
            $case['rows'],
            array_map(fn (ResolvedBid $bid) => [$bid->userId, intdiv($bid->amount->amount, 100), $bid->type->value], $resolution->bids),
        );
        $this->assertSame($case['price'] * 100, $resolution->price->amount);
        $this->assertSame($case['leader'], $resolution->leaderId);
        $this->assertSame($case['leaderMax'] * 100, $resolution->leaderMax->amount);
        $this->assertSame($case['reserveMet'], $resolution->reserveMet);
    }

    public function test_a_bid_below_the_minimum_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ProxyResolver)->resolve($this->state(['price' => 150, 'bids' => 2, 'leader' => 1, 'leaderAuto' => null, 'reserve' => null]), 2, $this->money(155), false);
    }

    public function test_the_leader_cannot_lower_or_hand_bid_against_themselves(): void
    {
        $state = $this->state(['price' => 150, 'bids' => 2, 'leader' => 1, 'leaderAuto' => 300, 'reserve' => null]);

        foreach ([[400, false], [300, true]] as [$max, $auto]) {
            try {
                (new ProxyResolver)->resolve($state, 1, $this->money($max), $auto);
                $this->fail('The leader bid against themselves.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_bid_types_are_kept(): void
    {
        $resolution = (new ProxyResolver)->resolve($this->state(['price' => 100, 'bids' => 0, 'leader' => null, 'leaderAuto' => null, 'reserve' => null]), 2, $this->money(100), false);

        $this->assertSame(BidType::Manual, $resolution->bids[0]->type);
    }

    /** @param  array{price: int, bids: int, leader: ?int, leaderAuto: ?int, reserve: ?int}  $state */
    private function state(array $state): BidState
    {
        return new BidState(
            starting: $this->money(100),
            increment: $this->money(10),
            reserve: $state['reserve'] === null ? null : $this->money($state['reserve']),
            price: $this->money($state['price']),
            bidCount: $state['bids'],
            leaderId: $state['leader'],
            leaderAutoMax: $state['leaderAuto'] === null ? null : $this->money($state['leaderAuto']),
            leaderAuto: $state['leaderAuto'] !== null,
        );
    }

    private function money(int $units): Money
    {
        return Money::minor($units * 100, 'BDT');
    }
}
