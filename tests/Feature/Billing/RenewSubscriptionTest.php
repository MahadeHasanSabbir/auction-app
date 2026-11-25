<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\RenewSubscription;
use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\SystemAccount;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCompanies;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

class RenewSubscriptionTest extends TestCase
{
    use InteractsWithCompanies;
    use InteractsWithLedger;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['auction.billing.grace_days' => 7]);
        $this->travelTo(now()->startOfSecond());
        $this->createPlatformAccounts();
    }

    private function renew(Subscription $subscription): string
    {
        return app(RenewSubscription::class)->handle($subscription);
    }

    /** A subscription whose period ended a day ago. */
    private function due(Subscription $subscription): Subscription
    {
        $subscription->forceFill(['current_period_start' => now()->subDays(31), 'current_period_end' => now()->subDay()])->save();

        return $subscription;
    }

    public function test_nothing_happens_before_the_period_ends(): void
    {
        [$company] = $this->company($this->plan());

        $this->assertSame('unchanged', $this->renew($company->subscription()->firstOrFail()));
        $this->assertSame(0, SubscriptionInvoice::query()->count());
    }

    public function test_a_due_plan_is_renewed_from_the_wallet(): void
    {
        [$company, , $wallet] = $this->company($this->plan());
        $this->fund($wallet, 500_000);
        $subscription = $this->due($company->subscription()->firstOrFail());
        $oldEnd = $subscription->current_period_end;

        $this->assertSame('renewed', $this->renew($subscription));

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertTrue($subscription->current_period_start?->equalTo($oldEnd), 'The new period continues where the old one ended.');
        $this->assertTrue($subscription->current_period_end?->equalTo(BillingInterval::Monthly->endFrom($oldEnd)));
        $this->assertSame(400_000, $wallet->fresh()?->balance->amount);
        $this->assertSame(InvoiceStatus::Paid, SubscriptionInvoice::query()->sole()->status);
        $this->assertLedgerBalanced();

        // Running it again changes nothing.
        $this->assertSame('unchanged', $this->renew($subscription));
        $this->assertSame(1, SubscriptionInvoice::query()->count());
    }

    public function test_an_empty_wallet_starts_the_grace_period_and_a_later_top_up_renews(): void
    {
        [$company, , $wallet] = $this->company($this->plan());
        $subscription = $this->due($company->subscription()->firstOrFail());

        $this->assertSame('past_due', $this->renew($subscription));

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);
        $this->assertTrue($subscription->allowsSelling() === false);
        $this->assertTrue($subscription->grace_ends_at?->equalTo(now()->addDays(7)));
        $this->assertSame(InvoiceStatus::Open, SubscriptionInvoice::query()->sole()->status);

        $this->travel(3)->days();
        $this->assertSame('unchanged', $this->renew($subscription), 'Still inside the grace period.');

        $this->fund($wallet, 500_000);
        $this->assertSame('renewed', $this->renew($subscription));

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNull($subscription->grace_ends_at);
        $this->assertSame(1, SubscriptionInvoice::query()->count());
        $this->assertLedgerBalanced();
    }

    public function test_the_subscription_expires_when_the_grace_period_ends(): void
    {
        [$company] = $this->company($this->plan());
        $subscription = $this->due($company->subscription()->firstOrFail());
        $this->renew($subscription);

        $this->travel(8)->days();

        $this->assertSame('expired', $this->renew($subscription));

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Expired, $subscription->status);
        $this->assertNotNull($subscription->ended_at);
        $this->assertSame(InvoiceStatus::Void, SubscriptionInvoice::query()->sole()->status);
    }

    public function test_a_cancelled_plan_expires_without_an_invoice(): void
    {
        [$company, , $wallet] = $this->company($this->plan());
        $this->fund($wallet, 500_000);
        $subscription = $this->due($company->subscription()->firstOrFail());
        $subscription->forceFill(['cancelled_at' => now()->subDays(10)])->save();

        $this->assertSame('expired', $this->renew($subscription));

        $this->assertSame(SubscriptionStatus::Expired, $subscription->fresh()?->status);
        $this->assertSame(0, SubscriptionInvoice::query()->count());
        $this->assertSame(500_000, $wallet->fresh()?->balance->amount);
    }

    public function test_a_scheduled_downgrade_is_billed_at_the_new_price(): void
    {
        $big = $this->plan(['price_monthly' => 300_000, 'price_yearly' => 3_000_000, 'max_users' => 10]);
        $small = $this->plan(['price_monthly' => 100_000, 'max_users' => 3]);
        [$company, , $wallet] = $this->company($big);
        $this->fund($wallet, 500_000);
        $subscription = $this->due($company->subscription()->firstOrFail());
        $subscription->forceFill(['pending_plan_id' => $small->id, 'pending_billing_interval' => BillingInterval::Monthly])->save();

        $this->assertSame('renewed', $this->renew($subscription));

        $subscription->refresh();
        $this->assertSame($small->id, $subscription->plan_id);
        $this->assertSame(100_000, $subscription->price->amount);
        $this->assertNull($subscription->pending_plan_id);
        $this->assertSame(400_000, $wallet->fresh()?->balance->amount);
        $this->assertSame(3, $subscription->maxUsers());
    }

    public function test_a_trial_that_ends_is_invoiced(): void
    {
        [$company, , $wallet] = $this->company($this->plan(), SubscriptionStatus::Trialing);
        $subscription = $company->subscription()->firstOrFail();
        $subscription->forceFill(['trial_ends_at' => now()->subHour(), 'current_period_start' => now()->subDays(14), 'current_period_end' => now()->subHour()])->save();

        $this->assertSame('past_due', $this->renew($subscription));
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->fresh()?->status);

        $this->fund($wallet, 500_000);
        $this->assertSame('renewed', $this->renew($subscription));
        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()?->status);
    }

    public function test_a_first_payment_never_made_expires_the_subscription(): void
    {
        [$company] = $this->company($this->plan(), SubscriptionStatus::Incomplete);
        $subscription = $company->subscription()->firstOrFail();
        $subscription->forceFill(['current_period_start' => null, 'current_period_end' => null])->save();

        // No invoice at all counts as abandoned.
        $this->assertSame('expired', $this->renew($subscription));
    }

    public function test_the_command_renews_everything_that_is_due_and_survives_a_failure(): void
    {
        [$a, , $walletA] = $this->company($this->plan());
        [$b] = $this->company($this->plan());
        [$c] = $this->company($this->plan());
        $this->fund($walletA, 500_000);
        $this->due($a->subscription()->firstOrFail());
        $this->due($b->subscription()->firstOrFail());

        $this->artisan('subscriptions:renew')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::Active, $a->subscription()->first()?->status);
        $this->assertSame(SubscriptionStatus::PastDue, $b->subscription()->first()?->status);
        $this->assertTrue($c->subscription()->first()?->current_period_end?->isFuture());
        $this->assertSame(2, SubscriptionInvoice::query()->count());
        $this->assertLedgerBalanced();
        $this->assertSame(100_000, $this->system(SystemAccount::SubscriptionRevenue)->balance->amount);
    }
}
