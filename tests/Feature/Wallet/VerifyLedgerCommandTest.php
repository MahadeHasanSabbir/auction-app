<?php

namespace Tests\Feature\Wallet;

use App\Enums\HoldType;
use App\Enums\LedgerTransactionType;
use App\Enums\SystemAccount;
use App\Models\Auction;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\Wallet;
use App\Services\Ledger\Ledger;
use App\Services\Ledger\LedgerVerifier;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

class VerifyLedgerCommandTest extends TestCase
{
    use InteractsWithLedger;
    use RefreshDatabase;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPlatformAccounts();
        $this->wallet = $this->fund(Wallet::factory()->create(), 10_000);
    }

    public function test_a_consistent_ledger_passes(): void
    {
        $this->artisan('ledger:verify')
            ->expectsOutputToContain('The ledger balances.')
            ->assertSuccessful();
    }

    public function test_a_cached_balance_that_was_edited_directly_is_reported(): void
    {
        DB::table('wallets')->where('id', $this->wallet->id)->update(['balance' => 99_999]);

        $this->artisan('ledger:verify')
            ->expectsOutputToContain("Wallet {$this->wallet->id} caches a balance of 99999")
            ->assertFailed();
    }

    public function test_an_unbalanced_transaction_is_reported(): void
    {
        $entry = LedgerEntry::query()->where('wallet_id', $this->wallet->id)->sole();
        DB::table('ledger_entries')->where('id', $entry->id)->update(['amount' => 9_000]);

        $problems = app(LedgerVerifier::class)->problems();

        $this->assertContains("Transaction {$entry->ledger_transaction_id} sums to -1000, not zero.", $problems);
    }

    public function test_a_transaction_with_a_single_entry_is_reported(): void
    {
        $transaction = new LedgerTransaction;
        $transaction->forceFill(['type' => LedgerTransactionType::Adjustment])->save();

        $this->assertContains("Transaction {$transaction->id} has 0 entries; at least two are needed.", app(LedgerVerifier::class)->problems());
    }

    public function test_a_held_amount_that_does_not_match_the_holds_is_reported(): void
    {
        DB::table('wallets')->where('id', $this->wallet->id)->update(['held' => 500]);

        $problems = app(LedgerVerifier::class)->problems();

        $this->assertContains("Wallet {$this->wallet->id} caches 500 held; its active holds sum to 0.", $problems);
    }

    public function test_escrow_must_equal_the_open_orders(): void
    {
        Order::factory()->create(['amount' => 4_000, 'seller_payout' => 4_000]);

        $this->assertContains('Escrow has 0; the open orders add up to 4000.', app(LedgerVerifier::class)->problems());
    }

    public function test_a_bid_hold_must_belong_to_the_leader_of_a_live_auction(): void
    {
        $auction = Auction::factory()->live()->create();
        $hold = app(Ledger::class)->hold($this->wallet, Money::minor(1_000), HoldType::Bid, $auction);

        $this->assertContains(
            "Hold {$hold->id} on auction {$auction->id} does not belong to the leader of a live auction.",
            app(LedgerVerifier::class)->problems(),
        );

        $auction->forceFill(['leader_id' => $this->wallet->user_id])->save();

        $this->assertSame([], app(LedgerVerifier::class)->problems());
    }

    public function test_an_overdrawn_member_wallet_is_reported(): void
    {
        $escrow = Wallet::forSystem(SystemAccount::Escrow);

        // Bypass the ledger's checks to simulate a bug elsewhere.
        DB::table('ledger_entries')->insert([
            ['ledger_transaction_id' => LedgerTransaction::query()->value('id'), 'wallet_id' => $this->wallet->id, 'amount' => -20_000, 'component' => 'principal', 'balance_after' => -10_000, 'created_at' => now()],
            ['ledger_transaction_id' => LedgerTransaction::query()->value('id'), 'wallet_id' => $escrow->id, 'amount' => 20_000, 'component' => 'principal', 'balance_after' => 20_000, 'created_at' => now()],
        ]);
        DB::table('wallets')->where('id', $this->wallet->id)->update(['balance' => -10_000]);

        $this->assertContains("Wallet {$this->wallet->id} is overdrawn: balance -10000, held 0.", app(LedgerVerifier::class)->problems());
    }
}
