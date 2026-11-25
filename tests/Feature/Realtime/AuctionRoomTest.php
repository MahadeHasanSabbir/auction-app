<?php

namespace Tests\Feature\Realtime;

use App\Actions\Bidding\PlaceBid;
use App\Livewire\Auctions\Show;
use App\Models\Auction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Realtime\AuctionSnapshot;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

/** The auction page as a live room: the snapshot it starts from, and the cards around it. */
class AuctionRoomTest extends TestCase
{
    use InteractsWithLedger;
    use RefreshDatabase;

    private Auction $auction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPlatformAccounts();
        $this->auction = Auction::factory()->live()->create([
            'starting_price' => 100_000,
            'min_increment' => 5_000,
            'ends_at' => now()->addHour(),
        ]);
    }

    private function buyer(): User
    {
        $buyer = User::factory()->create();
        $this->fund(Wallet::factory()->create(['user_id' => $buyer->id]), 1_000_000);

        return $buyer;
    }

    public function test_the_page_starts_the_room_from_a_snapshot_without_polling(): void
    {
        app(PlaceBid::class)->handle($this->buyer(), $this->auction, Money::minor(100_000));

        $this->get(route('auctions.show', $this->auction))
            ->assertOk()
            ->assertSee('auctionRoom(', false)
            ->assertSee('apply(', false)
            ->assertDontSee('wire:poll', false);
    }

    public function test_a_bidder_is_known_on_their_own_page_by_reference_not_by_name(): void
    {
        $buyer = $this->buyer();
        app(PlaceBid::class)->handle($buyer, $this->auction, Money::minor(100_000));

        $this->actingAs($buyer)
            ->get(route('auctions.show', $this->auction))
            ->assertOk()
            ->assertSee(AuctionSnapshot::ref($this->auction->id, $buyer->id))
            ->assertDontSee($buyer->maskedName());
    }

    public function test_a_wallet_change_re_renders_the_viewer_s_figures(): void
    {
        $buyer = $this->buyer();

        Livewire::actingAs($buyer)
            ->test(Show::class, ['auction' => $this->auction])
            ->assertSee('You can bid up to')
            ->dispatch('wallet-updated')
            ->assertOk();
    }

    public function test_open_cards_follow_their_auction_and_closed_ones_do_not(): void
    {
        Auction::factory()->sold()->create(['title' => 'Closed already']);

        $this->get(route('auctions.index'))
            ->assertOk()
            ->assertSee('liveAuction(', false);

        $html = view('components.auction.card', ['auction' => Auction::query()->where('title', 'Closed already')->forCards()->firstOrFail()])->render();
        $this->assertStringNotContainsString('liveAuction', $html);
    }
}
