<?php

namespace Tests\Feature\Auctions;

use App\Actions\Auctions\CancelAuction;
use App\Actions\Bidding\BidResult;
use App\Actions\Bidding\PlaceBid;
use App\Actions\Bidding\ToggleWatchlist;
use App\Enums\AuctionStatus;
use App\Enums\BidType;
use App\Enums\HoldStatus;
use App\Models\Auction;
use App\Models\AutoBid;
use App\Models\Bid;
use App\Models\Company;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletHold;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

/** Placing bids: prices, holds, automatic bids, limits and anti-sniping. */
class BiddingTest extends TestCase
{
    use InteractsWithLedger;
    use RefreshDatabase;

    private Auction $auction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfSecond());
        $this->createPlatformAccounts();

        // Starts at 1,000.00 with 50.00 increments; ends in two hours.
        $this->auction = Auction::factory()->live()->create([
            'starting_price' => 100_000,
            'min_increment' => 5_000,
            'ends_at' => now()->addHours(2),
        ]);
    }

    /** A buyer with a wallet funded with the given whole units. */
    private function buyer(int $units = 10_000): User
    {
        $buyer = User::factory()->create();
        $this->fund(Wallet::factory()->create(['user_id' => $buyer->id]), $units * 100);

        return $buyer;
    }

    private function bid(User $buyer, int $units, bool $auto = false, ?string $key = null): BidResult
    {
        return app(PlaceBid::class)->handle($buyer, $this->auction, Money::minor($units * 100), $auto, $key);
    }

    private function holdOf(User $buyer): ?WalletHold
    {
        return WalletHold::query()->active()->where('auction_id', $this->auction->id)->whereHas('wallet', fn ($q) => $q->where('user_id', $buyer->id))->first();
    }

    private function refused(callable $callback, string $key = 'amount'): void
    {
        try {
            $callback();
            $this->fail('The bid was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }

    public function test_the_first_bid_leads_and_holds_its_amount(): void
    {
        $buyer = $this->buyer();

        $result = $this->bid($buyer, 1_000);

        $this->assertTrue($result->leading);
        $this->auction->refresh();
        $this->assertSame(100_000, $this->auction->current_price->amount);
        $this->assertSame(1, $this->auction->bid_count);
        $this->assertSame($buyer->id, $this->auction->leader_id);
        $this->assertSame(100_000, $this->holdOf($buyer)?->amount->amount);
        $this->assertSame(105_000, $result->nextMinimum->amount);
        $this->assertLedgerBalanced();
    }

    public function test_being_outbid_releases_the_hold_and_moves_it_to_the_new_leader(): void
    {
        $first = $this->buyer();
        $second = $this->buyer();

        $this->bid($first, 1_000);
        $this->bid($second, 1_100);

        $this->assertNull($this->holdOf($first));
        $this->assertSame(110_000, $this->holdOf($second)?->amount->amount);
        $this->assertSame(1, WalletHold::query()->where('auction_id', $this->auction->id)->where('status', HoldStatus::Released)->count());
        $this->assertSame([1, 2], Bid::query()->where('auction_id', $this->auction->id)->orderBy('seq')->pluck('seq')->all());
        $this->assertLedgerBalanced();
    }

    public function test_an_automatic_bid_holds_its_maximum_and_defends_the_lead(): void
    {
        $proxy = $this->buyer();
        $rival = $this->buyer();

        $this->bid($proxy, 3_000, auto: true);
        $this->assertSame(100_000, $this->auction->refresh()->current_price->amount);
        $this->assertSame(300_000, $this->holdOf($proxy)?->amount->amount);

        $result = $this->bid($rival, 1_500);

        $this->assertFalse($result->leading);
        $this->auction->refresh();
        $this->assertSame($proxy->id, $this->auction->leader_id);
        $this->assertSame(155_000, $this->auction->current_price->amount);
        $this->assertNull($this->holdOf($rival));
        $this->assertSame(
            [[$rival->id, BidType::Manual], [$proxy->id, BidType::Proxy]],
            Bid::query()->where('auction_id', $this->auction->id)->where('seq', '>', 1)->orderBy('seq')->get()->map(fn (Bid $b) => [$b->user_id, $b->type])->all(),
        );
        $this->assertLedgerBalanced();
    }

    public function test_a_higher_maximum_takes_over_and_the_old_one_lapses(): void
    {
        $first = $this->buyer();
        $second = $this->buyer();

        $this->bid($first, 2_000, auto: true);
        $this->bid($second, 5_000, auto: true);

        $this->auction->refresh();
        $this->assertSame($second->id, $this->auction->leader_id);
        $this->assertSame(205_000, $this->auction->current_price->amount);
        $this->assertFalse(AutoBid::query()->where('user_id', $first->id)->value('is_active'));
        $this->assertSame(500_000, $this->holdOf($second)?->amount->amount);
        $this->assertNull($this->holdOf($first));
        $this->assertLedgerBalanced();
    }

    public function test_the_leader_raises_their_maximum_and_the_hold_follows(): void
    {
        $buyer = $this->buyer();
        $this->bid($buyer, 2_000, auto: true);

        $this->refused(fn () => $this->bid($buyer, 1_500));
        $this->refused(fn () => $this->bid($buyer, 2_000, auto: true));

        $this->bid($buyer, 4_000, auto: true);

        $this->assertSame(400_000, $this->holdOf($buyer)?->amount->amount);
        $this->assertSame(1, $this->auction->refresh()->bid_count);
        $this->assertLedgerBalanced();
    }

    public function test_a_covered_reserve_is_met_at_once(): void
    {
        $this->auction->forceFill(['reserve_price' => 250_000])->save();
        $buyer = $this->buyer();

        $this->bid($buyer, 3_000, auto: true);

        $this->auction->refresh();
        $this->assertSame(250_000, $this->auction->current_price->amount);
        $this->assertTrue($this->auction->reserve_met);
    }

    public function test_a_bid_must_reach_the_minimum(): void
    {
        $this->refused(fn () => $this->bid($this->buyer(), 999));

        $this->bid($this->buyer(), 1_000);
        $this->refused(fn () => $this->bid($this->buyer(), 1_049));
    }

    public function test_a_maximum_may_use_only_the_biddable_share_of_the_balance(): void
    {
        // 80% of 1,000 is 800, below the 1,000 starting price.
        $this->refused(fn () => $this->bid($this->buyer(1_000), 1_000));

        $buyer = $this->buyer(2_500);
        $this->refused(fn () => $this->bid($buyer, 2_001, auto: true));
        $this->assertTrue($this->bid($buyer, 2_000, auto: true)->leading);

        // Raising counts the money this auction already holds: 80% of 3,000.
        $this->fund(Wallet::query()->where('user_id', $buyer->id)->firstOrFail(), 50_000);
        $this->refused(fn () => $this->bid($buyer, 2_401, auto: true));
        $this->assertTrue($this->bid($buyer, 2_400, auto: true)->leading);
        $this->assertLedgerBalanced();
    }

    public function test_bidding_closes_at_the_end_time_even_before_the_closer_runs(): void
    {
        $this->travelTo($this->auction->ends_at);

        $this->refused(fn () => $this->bid($this->buyer(), 1_000));
        $this->assertSame(AuctionStatus::Live, $this->auction->refresh()->status);
    }

    public function test_only_live_auctions_take_bids(): void
    {
        $this->auction->forceFill(['status' => AuctionStatus::Scheduled, 'starts_at' => now()->addHour()])->save();

        $this->refused(fn () => $this->bid($this->buyer(), 1_000));
    }

    public function test_sellers_staff_and_unverified_buyers_cannot_bid(): void
    {
        $company = Company::factory()->create();

        foreach ([
            User::factory()->companyOwner($company)->create(),
            User::factory()->platformStaff()->create(),
            User::factory()->unverified()->create(),
        ] as $user) {
            try {
                $this->bid($user, 1_000);
                $this->fail("A {$user->role->value} placed a bid.");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_bid_in_the_last_minutes_extends_the_auction_up_to_the_limit(): void
    {
        $this->auction->forceFill(['ends_at' => now()->addSeconds(60), 'original_ends_at' => now()->addSeconds(60), 'anti_snipe_max_extensions' => 2])->save();
        $a = $this->buyer();
        $b = $this->buyer();

        $this->assertTrue($this->bid($a, 1_000)->extended);
        $this->auction->refresh();
        $this->assertTrue($this->auction->ends_at?->equalTo(now()->addSeconds(120)));
        $this->assertSame(1, $this->auction->extension_count);
        $this->assertTrue($this->auction->original_ends_at?->lessThan($this->auction->ends_at));

        $this->travel(90)->seconds();
        $this->assertTrue($this->bid($b, 1_100)->extended);

        $this->travel(90)->seconds();
        $this->assertFalse($this->bid($a, 1_200)->extended);
        $this->assertSame(2, $this->auction->refresh()->extension_count);
    }

    public function test_a_bid_outside_the_window_does_not_extend(): void
    {
        $this->assertFalse($this->bid($this->buyer(), 1_000)->extended);
        $this->assertTrue($this->auction->refresh()->ends_at?->equalTo(now()->addHours(2)));
    }

    public function test_the_same_form_sent_twice_places_one_bid(): void
    {
        $buyer = $this->buyer();

        $first = $this->bid($buyer, 1_000, key: 'form-1');
        $second = $this->bid($buyer, 1_000, key: 'form-1');

        $this->assertEquals($first->toArray(), $second->toArray());
        $this->assertSame(1, $this->auction->refresh()->bid_count);
    }

    public function test_a_buyer_is_limited_to_a_number_of_bids_per_minute(): void
    {
        config(['auction.bidding.bids_per_minute' => 2]);
        $buyer = $this->buyer();

        $this->bid($buyer, 1_000, auto: true);
        $this->bid($buyer, 1_500, auto: true);
        $this->refused(fn () => $this->bid($buyer, 2_000, auto: true));
    }

    public function test_a_live_auction_without_bids_is_cancelled_by_its_owner_and_with_bids_only_by_staff(): void
    {
        $owner = User::factory()->companyOwner($this->auction->company_id)->create();
        $buyer = $this->buyer();

        $this->bid($buyer, 1_000);

        try {
            app(CancelAuction::class)->handle($owner, $this->auction);
            $this->fail('The owner cancelled an auction with bids.');
        } catch (AuthorizationException) {
            $this->assertSame(AuctionStatus::Live, $this->auction->refresh()->status);
        }

        app(CancelAuction::class)->handle(User::factory()->platformStaff()->create(), $this->auction, 'Listing breaks the rules.');

        $this->assertSame(AuctionStatus::Cancelled, $this->auction->refresh()->status);
        $this->assertNull($this->holdOf($buyer));
        $this->assertLedgerBalanced();

        $quiet = Auction::factory()->live()->create(['company_id' => $this->auction->company_id]);
        $this->assertSame(AuctionStatus::Cancelled, app(CancelAuction::class)->handle($owner, $quiet)->status);
    }

    public function test_a_buyer_watches_and_unwatches_an_auction(): void
    {
        $buyer = $this->buyer();

        $this->assertTrue(app(ToggleWatchlist::class)->handle($buyer, $this->auction));
        $this->assertTrue($buyer->watchedAuctions()->whereKey($this->auction->id)->exists());
        $this->assertFalse(app(ToggleWatchlist::class)->handle($buyer, $this->auction));
        $this->assertFalse($buyer->watchedAuctions()->whereKey($this->auction->id)->exists());
    }
}
