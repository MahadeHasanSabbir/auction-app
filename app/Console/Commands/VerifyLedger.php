<?php

namespace App\Console\Commands;

use App\Services\Ledger\LedgerVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('ledger:verify')]
#[Description('Check that every ledger transaction balances and every cached balance, hold and escrow total matches its rows')]
class VerifyLedger extends Command
{
    public function handle(LedgerVerifier $verifier): int
    {
        $problems = $verifier->problems();

        if ($problems === []) {
            $this->components->info('The ledger balances.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        Log::critical('ledger:verify found '.count($problems).' problem(s).', ['problems' => $problems]);

        return self::FAILURE;
    }
}
