<?php

namespace App\Console\Commands;

use App\Actions\Billing\RenewSubscription;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Throwable;

/**
 * Renews plans whose period is over, retries unpaid ones and expires those
 * past their grace period. Safe to run as often as you like.
 */
class RenewSubscriptions extends Command
{
    protected $signature = 'subscriptions:renew';

    protected $description = 'Renew subscriptions that are due, retry past-due ones and expire lapsed ones';

    public function handle(RenewSubscription $renew): int
    {
        $counts = ['renewed' => 0, 'past_due' => 0, 'expired' => 0, 'unchanged' => 0, 'failed' => 0];

        Subscription::query()
            ->whereIn('status', [
                SubscriptionStatus::Incomplete, SubscriptionStatus::Trialing,
                SubscriptionStatus::Active, SubscriptionStatus::PastDue,
            ])
            ->where(fn ($query) => $query
                ->whereIn('status', [SubscriptionStatus::Incomplete, SubscriptionStatus::PastDue])
                ->orWhere('current_period_end', '<=', now()))
            ->lazyById(100)
            ->each(function (Subscription $subscription) use ($renew, &$counts): void {
                try {
                    $counts[$renew->handle($subscription)]++;
                } catch (Throwable $e) {
                    // One company's problem must not stop the others.
                    report($e);
                    $counts['failed']++;
                }
            });

        $this->info(collect($counts)->map(fn (int $n, string $what) => "{$n} {$what}")->implode(', '));

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
