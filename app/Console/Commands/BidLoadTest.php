<?php

namespace App\Console\Commands;

use App\Actions\Bidding\PlaceBid;
use App\Enums\HoldType;
use App\Enums\LedgerTransactionType;
use App\Enums\SystemAccount;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletHold;
use App\Services\Ledger\Ledger;
use App\Services\Ledger\LedgerVerifier;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

/**
 * Fires bids at one auction from several processes at once and checks what
 * must hold afterwards: bid numbers run 1..n without gaps, bid_count matches,
 * exactly one active bid hold and it belongs to the leader, and ledger:verify
 * finds nothing. Development only: it creates an auction, buyers and money in
 * the configured database (use a scratch copy, not one you care about).
 */
class BidLoadTest extends Command
{
    protected $signature = 'bids:load-test
        {--buyers=10 : Buyers, one process each}
        {--bids=20 : Bids each buyer tries}
        {--worker= : (internal) auction id for a worker process}
        {--buyer= : (internal) buyer id for a worker process}';

    protected $description = 'Concurrent bids at one auction, then check the sequence, the hold and the ledger (development only)';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('bids:load-test only runs in the local or testing environment.');

            return self::FAILURE;
        }

        config(['auction.bidding.bids_per_minute' => 100_000]);

        return $this->option('worker') !== null ? $this->work() : $this->coordinate();
    }

    private function coordinate(): int
    {
        $buyers = max(2, (int) $this->option('buyers'));
        $bids = max(1, (int) $this->option('bids'));

        [$auction, $bidders] = $this->setUp($buyers);
        $this->info("Auction #{$auction->id}: {$buyers} processes × {$bids} bids.");

        $started = microtime(true);
        $processes = [];

        foreach ($bidders as $bidder) {
            $process = new Process([PHP_BINARY, base_path('artisan'), 'bids:load-test', '--worker='.$auction->id, '--buyer='.$bidder->id, '--bids='.$bids], base_path());
            $process->setTimeout(600)->start();
            $processes[] = $process;
        }

        $placed = 0;
        $refused = 0;

        foreach ($processes as $process) {
            $process->wait();

            if (! $process->isSuccessful()) {
                $this->error(trim($process->getErrorOutput()) ?: trim($process->getOutput()));

                continue;
            }

            [$ok, $no] = array_map('intval', explode(' ', trim($process->getOutput())) + [0, 0]);

            foreach (array_filter(explode("\n", trim($process->getErrorOutput()))) as $reason) {
                $this->line("  refused: {$reason}");
            }
            $placed += $ok;
            $refused += $no;
        }

        $seconds = microtime(true) - $started;

        return $this->check($auction->refresh(), $placed, $refused, $seconds);
    }

    /** @return array{Auction, list<User>} */
    private function setUp(int $buyers): array
    {
        $ledger = app(Ledger::class);

        $auction = Auction::factory()->live()->create(['ends_at' => now()->addHour()]);
        $bidders = [];

        for ($i = 0; $i < $buyers; $i++) {
            $user = User::factory()->create(['email' => 'load-'.Str::lower(Str::random(10)).'@example.test']);
            $wallet = Wallet::query()->where('user_id', $user->id)->first() ?? Wallet::factory()->create(['user_id' => $user->id]);

            $ledger->transaction(LedgerTransactionType::Adjustment)
                ->describe('bids:load-test funds')
                ->credit($wallet, Money::minor(100_000_000))
                ->debit($ledger->system(SystemAccount::GatewayClearing), Money::minor(100_000_000))
                ->post();

            $bidders[] = $user;
        }

        return [$auction, $bidders];
    }

    /** One process: bid the minimum (or a little more) again and again. */
    private function work(): int
    {
        $bidder = User::query()->findOrFail((int) $this->option('buyer'));
        $placeBid = app(PlaceBid::class);
        $ok = 0;
        $no = 0;

        for ($i = 0; $i < (int) $this->option('bids'); $i++) {
            $auction = Auction::query()->findOrFail((int) $this->option('worker'));

            if ($auction->leader_id === $bidder->id) {
                continue;
            }

            $amount = $auction->minimumNextBid()->plus(Money::minor(random_int(0, 3) * 100));

            try {
                $placeBid->handle($bidder, $auction, $amount, key: (string) Str::uuid());
                $ok++;
            } catch (ValidationException $e) {
                // Outbid between reading the price and bidding: expected under load.
                $no++;
                $reasons[$e->getMessage()] = ($reasons[$e->getMessage()] ?? 0) + 1;
            }
        }

        $this->line("{$ok} {$no}");

        foreach ($reasons ?? [] as $reason => $count) {
            fwrite(STDERR, "{$count}× {$reason}\n");
        }

        return self::SUCCESS;
    }

    private function check(Auction $auction, int $placed, int $refused, float $seconds): int
    {
        $seqs = Bid::query()->where('auction_id', $auction->id)->orderBy('seq')->pluck('seq')->all();
        $holds = WalletHold::query()->active()->where('auction_id', $auction->id)->where('type', HoldType::Bid)->with('wallet:id,user_id')->get();
        $problems = app(LedgerVerifier::class)->problems();

        $results = [
            ['Bids accepted / refused', "{$placed} / {$refused}", true],
            ['Throughput', round(($placed + $refused) / max($seconds, 0.001), 1).' attempts/s', true],
            ['Bid numbers 1..n without gaps', count($seqs).' bids', $seqs === range(1, max(1, count($seqs))) || $seqs === []],
            ['bid_count matches the rows', (string) $auction->bid_count, $auction->bid_count === count($seqs)],
            ['Exactly one active bid hold', (string) $holds->count(), $holds->count() === 1],
            ['The hold is the leader\'s', '', $holds->count() === 1 && $holds->first()?->wallet?->user_id === $auction->leader_id],
            ['ledger:verify', count($problems).' problem(s)', $problems === []],
        ];

        $this->table(['Check', 'Value', 'OK'], array_map(fn (array $row) => [$row[0], $row[1], $row[2] ? 'yes' : 'NO'], $results));

        foreach (array_slice($problems, 0, 10) as $problem) {
            $this->warn($problem);
        }

        $failed = collect($results)->contains(fn (array $row) => ! $row[2]);
        $this->{$failed ? 'error' : 'info'}($failed ? 'The load test found a problem.' : 'All checks passed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
