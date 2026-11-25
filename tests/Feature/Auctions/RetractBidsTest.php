<?php

namespace Tests\Feature\Auctions;

use App\Actions\Auctions\CloseAuction;
use App\Actions\Bidding\PlaceBid;
use App\Actions\Bidding\RetractBids;
use App\Enums\AuctionCloseReason;
use App\Enums\StrikeSource;
use App\Livewire\Auctions\Show;
use App\Models\Auction;
use App\Models\AutoBid;
use App\Models\Bid;
use App\Models\Strike;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletHold;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

/** A buyer takes back their bids: who leads after, the holds, the window and the strike. */
class RetractBidsTest extends TestCase
{
    use InteractsWithLedger;
    use RefreshDatabase;

    private Auction $auction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfSecond());
        $this->createPlatformAccounts();

        // Starts at 1,000.00 with 50.00 increments; ends in a day.
        $this->auction = Auction::factory()->live()->create([
            'starting_price' => 100_000,
            'min_increment' => 5_000,
            'ends_at' => now()->addDay(),
        ]);
    }

    private function buyer(int $units = 10_000): User
    {
        $buyer = User::factory()->create();
        $this->fund(Wallet::factory()->create(['user_id' => $buyer->id]), $units * 100);

        return $buyer;
    }

    private function bid(User $buyer, int $units, bool $auto = false): void
    {
        app(PlaceBid::class)->handle($buyer, $this->auction, Money::minor($units * 100), $auto);
    }

    private function retract(User $buyer): Auction
    {
        return app(RetractBids::class)->handle($buyer, $this->auction, 'I entered the wrong amount');
    }

    private function holdOf(User $buyer): ?int
    {
        return WalletHold::query()->active()->where('auction_id', $this->auction->id)
            ->whereHas('wallet', fn ($q) => $q->where('user_id', $buyer->id))->first()?->amount->amount;
    }

    private function refused(callable $callback): void
    {
        try {
            $callback();
            $this->fail('The retraction was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
    }

    public function test_the_leader_retracting_hands_the_lead_to_the_next_best_bid(): void
    {
        $first = $this->buyer();
        $second = $this->buyer();

        $this->bid($first, 1_000);
        $this->bid($second, 1_200);
        $this->bid($first, 2_000, auto: true);
        $this->auction->refresh();
        $this->assertSame($first->id, $this->auction->leader_id);
        $this->assertSame(125_000, $this->auction->current_price->amount);

        $this->retract($first);

        $this->auction->refresh();
        $this->assertSame($second->id, $this->auction->leader_id);
        $this->assertSame(120_000, $this->auction->current_price->amount);
        $this->assertNull($this->holdOf($first));
        $this->assertSame(120_000, $this->holdOf($second));
        $this->assertSame(0, Bid::query()->where('user_id', $first->id)->standing()->count());
        $this->assertFalse((bool) AutoBid::query()->where('user_id', $first->id)->value('is_active'));
        // Retracted bids keep their place in the sequence.
        $this->assertSame(3, $this->auction->bid_count);
        $this->assertSame(125_000, $this->auction->minimumNextBid()->amount);

        $strike = Strike::query()->sole();
        $this->assertSame($first->id, $strike->user_id);
        $this->assertSame(StrikeSource::Retraction, $strike->source);
        $this->assertLedgerBalanced();
    }

    public function test_a_bidder_who_cannot_cover_their_bid_any_more_is_passed_over(): void
    {
        $first = $this->buyer();
        $second = $this->buyer(1_500);
        $third = $this->buyer();

        $this->bid($first, 1_000);
        $this->bid($second, 1_100);
        $this->bid($third, 1_300);

        // The second bidder's money went to another auction since.
        $other = Auction::factory()->live()->create(['starting_price' => 100_000, 'min_increment' => 5_000, 'ends_at' => now()->addDay()]);
        app(PlaceBid::class)->handle($second, $other, Money::minor(120_000));

        $this->retract($third);

        $this->auction->refresh();
        $this->assertSame($first->id, $this->auction->leader_id);
        $this->assertSame(100_000, $this->auction->current_price->amount);
        $this->assertSame(100_000, $this->holdOf($first));
        $this->assertLedgerBalanced();
    }

    public function test_the_only_bidder_retracting_leaves_the_auction_at_its_start(): void
    {
        $buyer = $this->buyer();
        $this->bid($buyer, 1_000);

        $this->retract($buyer);

        $this->auction->refresh();
        $this->assertNull($this->auction->leader_id);
        $this->assertSame(100_000, $this->auction->current_price->amount);
        $this->assertFalse($this->auction->reserve_met);
        $this->assertSame(100_000, $this->auction->minimumNextBid()->amount);
        $this->assertNull($this->holdOf($buyer));

        // A new bid at the starting price is accepted, and the close counts it as no bids otherwise.
        $other = $this->buyer();
        $this->bid($other, 1_000);
        $this->assertSame($other->id, $this->auction->refresh()->leader_id);
        $this->assertLedgerBalanced();
    }

    public function test_an_auction_whose_bids_were_all_retracted_closes_as_no_bids(): void
    {
        $buyer = $this->buyer();
        $this->bid($buyer, 1_000);
        $this->retract($buyer);

        $this->travelTo($this->auction->ends_at->addSecond());
        app(CloseAuction::class)->handle($this->auction);

        $this->assertSame(AuctionCloseReason::NoBids, $this->auction->refresh()->close_reason);
        $this->assertLedgerBalanced();
    }

    public function test_a_bidder_behind_retracting_changes_neither_leader_nor_price(): void
    {
        $first = $this->buyer();
        $second = $this->buyer();
        $this->bid($first, 1_000);
        $this->bid($second, 1_500);

        $this->retract($first);

        $this->auction->refresh();
        $this->assertSame($second->id, $this->auction->leader_id);
        $this->assertSame(150_000, $this->auction->current_price->amount);
        $this->assertSame(150_000, $this->holdOf($second));
        $this->assertLedgerBalanced();
    }

    public function test_the_retraction_window_and_the_closing_minutes_are_enforced(): void
    {
        $buyer = $this->buyer();
        $this->bid($buyer, 1_000);

        $this->travel((int) config('auction.trust.retract_window_minutes') + 1)->minutes();
        $this->refused(fn () => $this->retract($buyer));

        $this->bid($buyer, 1_500, auto: true);
        $this->auction->forceFill(['ends_at' => now()->addMinutes((int) config('auction.trust.retract_closing_minutes') - 1)])->save();
        $this->refused(fn () => $this->retract($buyer));

        // Nothing to take back.
        $this->auction->forceFill(['ends_at' => now()->addDay()])->save();
        $this->refused(fn () => $this->retract($this->buyer()));

        config(['auction.trust.retract_window_minutes' => 0]);
        $this->refused(fn () => $this->retract($buyer));

        $this->assertSame(0, Strike::query()->count());
    }

    public function test_three_retractions_suspend_the_buyer(): void
    {
        $buyer = $this->buyer();
        $limit = (int) config('auction.trust.strike_limit');

        for ($i = 0; $i < $limit; $i++) {
            $this->bid($buyer, 1_000);
            $this->retract($buyer);
        }

        $this->assertFalse($buyer->refresh()->is_active);
        $this->assertNotNull($buyer->suspension_reason);
    }

    public function test_the_auction_page_offers_retraction_inside_the_window(): void
    {
        $buyer = $this->buyer();
        $this->bid($buyer, 1_000);

        Livewire::actingAs($buyer)->test(Show::class, ['auction' => $this->auction])
            ->assertSee(__('Take back my bids'))
            ->call('retract')
            ->assertHasErrors('retract')
            ->set('retractReason', 'typo')
            ->call('retract')
            ->assertHasNoErrors();

        $this->assertNull($this->auction->refresh()->leader_id);

        $this->travel((int) config('auction.trust.retract_window_minutes') + 1)->minutes();
        $this->bid($buyer, 1_000);
        $this->travel((int) config('auction.trust.retract_window_minutes') + 1)->minutes();

        Livewire::actingAs($buyer)->test(Show::class, ['auction' => $this->auction])
            ->assertDontSee(__('Take back my bids'));
    }
}
