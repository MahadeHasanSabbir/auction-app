<?php

namespace Tests\Feature\Wallet;

use App\Enums\HoldStatus;
use App\Enums\HoldType;
use App\Enums\LedgerComponent;
use App\Enums\LedgerTransactionType;
use App\Enums\SystemAccount;
use App\Models\Auction;
use App\Models\FeeRule;
use App\Models\LedgerTransaction;
use App\Models\Wallet;
use App\Services\Ledger\InsufficientFundsException;
use App\Services\Ledger\Ledger;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use InteractsWithLedger;
    use RefreshDatabase;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPlatformAccounts();
        $this->ledger = app(Ledger::class);
    }

    public function test_a_balanced_transaction_moves_money_and_records_running_balances(): void
    {
        $buyer = $this->fund(Wallet::factory()->create(), 100_000);
        $revenue = $this->ledger->system(SystemAccount::ServiceRevenue);

        $transaction = $this->ledger->transaction(LedgerTransactionType::Adjustment)
            ->describe('Test charge')
            ->debit($buyer, Money::minor(30_000))
            ->credit($revenue, Money::minor(30_000))
            ->post();

        // The caller's models are brought up to date.
        $this->assertSame(70_000, $buyer->balance->amount);
        $this->assertSame(30_000, $revenue->balance->amount);
        $this->assertSame(70_000, $buyer->fresh()?->balance->amount);

        $this->assertCount(2, $transaction->entries);
        $this->assertSame(-30_000, $transaction->entries[0]->amount->amount);
        $this->assertSame(70_000, $transaction->entries[0]->balance_after->amount);
        $this->assertLedgerBalanced();
    }

    public function test_entries_that_do_not_sum_to_zero_are_refused(): void
    {
        $buyer = $this->fund(Wallet::factory()->create(), 100_000);

        try {
            $this->ledger->transaction(LedgerTransactionType::Adjustment)
                ->debit($buyer, Money::minor(500))
                ->credit($this->ledger->system(SystemAccount::Escrow), Money::minor(400))
                ->post();
            $this->fail('An unbalanced transaction was posted.');
        } catch (LogicException) {
        }

        $this->assertSame(1, LedgerTransaction::query()->count());
        $this->assertLedgerBalanced();
    }

    public function test_a_transaction_needs_two_entries(): void
    {
        $this->expectException(LogicException::class);

        $this->ledger->transaction(LedgerTransactionType::Adjustment)
            ->credit(Wallet::factory()->create(), Money::zero())
            ->debit($this->ledger->system(SystemAccount::Escrow), Money::zero())
            ->post();
    }

    public function test_a_member_wallet_cannot_spend_more_than_it_has(): void
    {
        $buyer = $this->fund(Wallet::factory()->create(), 10_000);

        try {
            $this->ledger->transaction(LedgerTransactionType::SaleCapture)
                ->debit($buyer, Money::minor(10_001))
                ->credit($this->ledger->system(SystemAccount::Escrow), Money::minor(10_001))
                ->post();
            $this->fail('The wallet was overdrawn.');
        } catch (InsufficientFundsException $e) {
            $this->assertSame(10_000, $e->available->amount);
            $this->assertSame(10_001, $e->requested->amount);
        }

        // Nothing was written, not even the transaction row.
        $this->assertSame(1, LedgerTransaction::query()->count());
        $this->assertSame(10_000, $buyer->fresh()?->balance->amount);
        $this->assertSame(0, $this->system(SystemAccount::Escrow)->balance->amount);
        $this->assertLedgerBalanced();
    }

    public function test_held_money_is_not_available_to_spend(): void
    {
        $buyer = $this->fund(Wallet::factory()->create(), 10_000);
        $this->ledger->hold($buyer, Money::minor(6_000), HoldType::Bid, Auction::factory()->live()->create(['leader_id' => $buyer->user_id]));

        $this->assertSame(4_000, $buyer->available()->amount);

        $this->expectException(InsufficientFundsException::class);

        $this->ledger->transaction(LedgerTransactionType::SaleCapture)
            ->debit($buyer, Money::minor(4_001))
            ->credit($this->ledger->system(SystemAccount::Escrow), Money::minor(4_001))
            ->post();
    }

    public function test_platform_accounts_may_run_negative(): void
    {
        $this->fund(Wallet::factory()->create(), 50_000);

        $this->assertSame(-50_000, $this->system(SystemAccount::GatewayClearing)->balance->amount);
        $this->assertLedgerBalanced();
    }

    public function test_a_hold_sets_money_aside_and_a_release_gives_it_back(): void
    {
        $buyer = $this->fund(Wallet::factory()->create(), 10_000);
        $auction = Auction::factory()->live()->create(['leader_id' => $buyer->user_id]);

        $hold = $this->ledger->hold($buyer, Money::minor(7_000), HoldType::Bid, $auction);

        $this->assertSame(7_000, $buyer->fresh()?->held->amount);
        $this->assertLedgerBalanced();

        $this->ledger->release($hold);

        $this->assertSame(HoldStatus::Released, $hold->status);
        $this->assertNotNull($hold->released_at);
        $this->assertSame(0, $buyer->fresh()?->held->amount);
        $this->assertLedgerBalanced();

        // Releasing twice is a bug in the caller.
        $this->expectException(LogicException::class);
        $this->ledger->release($hold);
    }

    public function test_a_hold_cannot_exceed_the_available_balance(): void
    {
        $buyer = $this->fund(Wallet::factory()->create(), 10_000);

        $this->expectException(InsufficientFundsException::class);

        $this->ledger->hold($buyer, Money::minor(10_001), HoldType::Bid, Auction::factory()->live()->create());
    }

    public function test_a_capture_pays_from_the_hold_and_frees_the_rest(): void
    {
        $buyer = $this->fund(Wallet::factory()->create(), 10_000);
        $auction = Auction::factory()->live()->create(['leader_id' => $buyer->user_id]);
        $hold = $this->ledger->hold($buyer, Money::minor(9_000), HoldType::Bid, $auction);

        // The whole balance minus the hold is 1,000 available, yet the hold
        // itself pays for the capture.
        $this->ledger->transaction(LedgerTransactionType::SaleCapture)
            ->capturing($hold)
            ->debit($buyer, Money::minor(8_000))
            ->credit($this->ledger->system(SystemAccount::Escrow), Money::minor(8_000))
            ->post();

        $this->assertSame(HoldStatus::Captured, $hold->status);
        $this->assertSame(2_000, $buyer->balance->amount);
        $this->assertSame(0, $buyer->held->amount);

        // Escrow is checked against open orders; there are none in this test.
        $this->assertSame(8_000, $this->system(SystemAccount::Escrow)->balance->amount);
    }

    public function test_a_hold_is_captured_only_by_a_debit_of_its_wallet(): void
    {
        $buyer = $this->fund(Wallet::factory()->create(), 10_000);
        $other = $this->fund(Wallet::factory()->create(), 10_000);
        $hold = $this->ledger->hold($buyer, Money::minor(5_000), HoldType::Bid, Auction::factory()->live()->create());

        $this->expectException(LogicException::class);

        $this->ledger->transaction(LedgerTransactionType::SaleCapture)
            ->capturing($hold)
            ->debit($other, Money::minor(5_000))
            ->credit($this->ledger->system(SystemAccount::Escrow), Money::minor(5_000))
            ->post();
    }

    public function test_a_released_hold_cannot_be_captured(): void
    {
        $buyer = $this->fund(Wallet::factory()->create(), 10_000);
        $hold = $this->ledger->hold($buyer, Money::minor(5_000), HoldType::Bid, Auction::factory()->live()->create());
        $this->ledger->release($hold);

        $this->expectException(LogicException::class);

        $this->ledger->transaction(LedgerTransactionType::SaleCapture)
            ->capturing($hold)
            ->debit($buyer, Money::minor(5_000))
            ->credit($this->ledger->system(SystemAccount::Escrow), Money::minor(5_000))
            ->post();
    }

    public function test_an_idempotency_key_posts_once(): void
    {
        $buyer = Wallet::factory()->create();

        $post = fn () => $this->ledger->transaction(LedgerTransactionType::Promotion)
            ->idempotent('promo:1')
            ->credit($buyer, Money::minor(1_000))
            ->debit($this->ledger->system(SystemAccount::Promotions), Money::minor(1_000))
            ->post();

        $first = $post();
        $second = $post();

        $this->assertSame($first->id, $second->id);
        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame(1_000, $buyer->fresh()?->balance->amount);
        $this->assertLedgerBalanced();
    }

    public function test_an_idempotency_key_cannot_be_reused_for_another_kind_of_transaction(): void
    {
        $buyer = Wallet::factory()->create();

        $this->ledger->transaction(LedgerTransactionType::Promotion)
            ->idempotent('key')
            ->credit($buyer, Money::minor(1_000))
            ->debit($this->ledger->system(SystemAccount::Promotions), Money::minor(1_000))
            ->post();

        $this->expectException(LogicException::class);

        $this->ledger->transaction(LedgerTransactionType::Adjustment)
            ->idempotent('key')
            ->credit($buyer, Money::minor(1_000))
            ->debit($this->ledger->system(SystemAccount::Promotions), Money::minor(1_000))
            ->post();
    }

    public function test_entries_record_the_rule_or_agreement_behind_a_charge(): void
    {
        $buyer = $this->fund(Wallet::factory()->create(), 10_000);
        $rule = FeeRule::factory()->create();

        $transaction = $this->ledger->transaction(LedgerTransactionType::Adjustment)
            ->debit($buyer, Money::minor(100))
            ->credit($this->ledger->system(SystemAccount::ServiceRevenue), Money::minor(100), LedgerComponent::ServiceCharge, $rule)
            ->post();

        $this->assertSame($rule->id, $transaction->entries[1]->fee_rule_id);
        $this->assertSame(LedgerComponent::ServiceCharge, $transaction->entries[1]->component);
    }

    public function test_wallets_in_another_currency_are_refused(): void
    {
        $dollars = Wallet::factory()->create(['currency' => 'USD']);

        $this->expectException(LogicException::class);

        $this->ledger->transaction(LedgerTransactionType::Adjustment)
            ->credit($dollars, Money::minor(100))
            ->debit($this->ledger->system(SystemAccount::Escrow), Money::minor(100))
            ->post();
    }

    public function test_lock_returns_fresh_copies_of_wallets_given_by_model_or_id(): void
    {
        $wallet = Wallet::factory()->create();
        DB::table('wallets')->where('id', $wallet->id)->update(['balance' => 123]);

        // RefreshDatabase runs every test inside a transaction, so the
        // outside-a-transaction guard cannot be exercised here.
        $locked = $this->ledger->lock($wallet->id, $this->ledger->system(SystemAccount::Escrow));

        $this->assertSame([$wallet->id, $this->ledger->system(SystemAccount::Escrow)->id], array_keys($locked));
        $this->assertSame(123, $locked[$wallet->id]->balance->amount);

        $this->expectException(LogicException::class);
        $this->ledger->lock(999_999);
    }

    public function test_platform_accounts_cannot_hold_money(): void
    {
        $this->expectException(LogicException::class);

        $this->ledger->hold($this->ledger->system(SystemAccount::Escrow), Money::minor(1), HoldType::Bid, Auction::factory()->live()->create());
    }

    public function test_a_hold_names_what_it_is_for(): void
    {
        $buyer = $this->fund(Wallet::factory()->create(), 10_000);

        $this->expectException(InvalidArgumentException::class);

        $this->ledger->hold($buyer, Money::minor(1_000), HoldType::Bid);
    }
}
