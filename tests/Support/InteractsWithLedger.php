<?php

namespace Tests\Support;

use App\Enums\LedgerTransactionType;
use App\Enums\SystemAccount;
use App\Models\Wallet;
use App\Services\Ledger\Ledger;
use App\Services\Ledger\LedgerVerifier;
use App\Support\Money;

/**
 * Helpers for tests that move money. Call createPlatformAccounts() (or seed
 * PlatformSeeder) first, and assertLedgerBalanced() at the end of every
 * money test.
 */
trait InteractsWithLedger
{
    protected function createPlatformAccounts(): void
    {
        foreach (SystemAccount::cases() as $account) {
            Wallet::factory()->system($account)->create();
        }
    }

    /** Puts money in a wallet as an adjustment from gateway clearing. */
    protected function fund(Wallet $wallet, int $minor): Wallet
    {
        $ledger = app(Ledger::class);

        $ledger->transaction(LedgerTransactionType::Adjustment)
            ->credit($wallet, Money::minor($minor))
            ->debit($ledger->system(SystemAccount::GatewayClearing), Money::minor($minor))
            ->post();

        return $wallet;
    }

    protected function assertLedgerBalanced(): void
    {
        $this->assertSame([], app(LedgerVerifier::class)->problems(), 'The ledger does not balance.');
    }

    protected function system(SystemAccount $account): Wallet
    {
        return Wallet::forSystem($account);
    }
}
