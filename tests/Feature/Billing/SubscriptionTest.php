<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\CancelSubscription;
use App\Actions\Billing\ChangePlan;
use App\Actions\Billing\PayInvoice;
use App\Actions\Billing\StartInvoicePayment;
use App\Actions\Billing\SubscribeToPlan;
use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\SystemAccount;
use App\Filament\Seller\Pages\Billing;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payments\CheckoutUrls;
use App\Services\Payments\FakeGateway;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithCompanies;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use InteractsWithCompanies;
    use InteractsWithLedger;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The database keeps whole seconds, so compare against whole seconds.
        $this->travelTo(now()->startOfSecond());
        $this->createPlatformAccounts();
    }

    public function test_a_paid_plan_waits_for_its_first_invoice(): void
    {
        $plan = $this->plan();
        [$company, $owner, $wallet] = $this->company();
        $this->fund($wallet, 500_000);

        ['subscription' => $subscription, 'invoice' => $invoice] = app(SubscribeToPlan::class)->handle($owner, $company, $plan, BillingInterval::Monthly);

        $this->assertSame(SubscriptionStatus::Incomplete, $subscription->status);
        $this->assertFalse($subscription->allowsSelling());
        $this->assertSame(100_000, $invoice?->amount->amount);
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertSame('INV-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT), $invoice->number);

        app(PayInvoice::class)->fromWallet($invoice);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertTrue($subscription->current_period_end?->equalTo($invoice->period_end));
        $this->assertSame(400_000, $wallet->fresh()?->balance->amount);
        $this->assertSame(100_000, $this->system(SystemAccount::SubscriptionRevenue)->balance->amount);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()?->status);
        $this->assertLedgerBalanced();
    }

    public function test_paying_twice_charges_once(): void
    {
        $plan = $this->plan();
        [$company, $owner, $wallet] = $this->company();
        $this->fund($wallet, 500_000);
        ['invoice' => $invoice] = app(SubscribeToPlan::class)->handle($owner, $company, $plan, BillingInterval::Monthly);

        app(PayInvoice::class)->fromWallet($invoice);
        app(PayInvoice::class)->fromWallet($invoice);

        $this->assertSame(400_000, $wallet->fresh()?->balance->amount);
        $this->assertLedgerBalanced();
    }

    public function test_a_wallet_that_cannot_cover_the_invoice_leaves_it_open(): void
    {
        $plan = $this->plan();
        [$company, $owner, $wallet] = $this->company();
        $this->fund($wallet, 50_000);
        ['invoice' => $invoice] = app(SubscribeToPlan::class)->handle($owner, $company, $plan, BillingInterval::Monthly);

        try {
            app(PayInvoice::class)->fromWallet($invoice);
            $this->fail('The wallet was charged more than it holds.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('wallet', $e->errors());
        }

        $this->assertSame(InvoiceStatus::Open, $invoice->fresh()?->status);
        $this->assertSame(SubscriptionStatus::Incomplete, $invoice->subscription->fresh()?->status);
        $this->assertSame(50_000, $wallet->fresh()?->balance->amount);
    }

    public function test_a_plan_with_a_trial_starts_it_once(): void
    {
        $plan = $this->plan(['trial_days' => 14]);
        [$company, $owner] = $this->company();

        ['subscription' => $subscription, 'invoice' => $invoice] = app(SubscribeToPlan::class)->handle($owner, $company, $plan, BillingInterval::Monthly);

        $this->assertNull($invoice);
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertTrue($subscription->allowsSelling());
        $this->assertTrue($subscription->trial_ends_at?->equalTo(now()->addDays(14)));

        // A company that has had its trial and let the plan lapse pays this time.
        $subscription->forceFill(['status' => SubscriptionStatus::Expired])->save();
        ['subscription' => $again, 'invoice' => $invoice] = app(SubscribeToPlan::class)->handle($owner, $company, $plan, BillingInterval::Monthly);

        $this->assertSame(SubscriptionStatus::Incomplete, $again->status);
        $this->assertNotNull($invoice);
        $this->assertSame(1, Subscription::query()->where('company_id', $company->id)->count());
    }

    public function test_a_free_plan_starts_at_once(): void
    {
        $plan = $this->plan(['price_monthly' => 0, 'price_yearly' => null]);
        [$company, $owner] = $this->company();

        ['subscription' => $subscription, 'invoice' => $invoice] = app(SubscribeToPlan::class)->handle($owner, $company, $plan, BillingInterval::Monthly);

        $this->assertNull($invoice);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
    }

    public function test_a_plan_that_is_not_for_sale_is_refused(): void
    {
        [$company, $owner] = $this->company();

        $cases = [
            $this->plan(['is_active' => false]),
            $this->plan(['is_public' => false]),
            $this->plan(['price_yearly' => null]),
        ];

        foreach ($cases as $index => $plan) {
            try {
                app(SubscribeToPlan::class)->handle($owner, $company, $plan, $index === 2 ? BillingInterval::Yearly : BillingInterval::Monthly);
                $this->fail("Plan case {$index} was accepted.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('plan', $e->errors());
            }
        }

        $this->assertNull($company->subscription()->first());
    }

    public function test_a_plan_too_small_for_the_team_is_refused(): void
    {
        $plan = $this->plan(['max_users' => 1]);
        [$company, $owner] = $this->company();
        User::factory()->companyMember($company)->create();

        $this->expectException(ValidationException::class);

        app(SubscribeToPlan::class)->handle($owner, $company, $plan, BillingInterval::Monthly);
    }

    public function test_only_the_company_owner_manages_billing(): void
    {
        $plan = $this->plan();
        [$company] = $this->company();
        $member = User::factory()->companyMember($company)->create();
        $outsider = User::factory()->companyOwner()->create();

        foreach ([$member, $outsider, User::factory()->create()] as $user) {
            try {
                app(SubscribeToPlan::class)->handle($user, $company, $plan, BillingInterval::Monthly);
                $this->fail('Someone but the owner chose a plan.');
            } catch (AuthorizationException) {
            }
        }

        $this->assertNull($company->subscription()->first());
    }

    public function test_choosing_a_plan_twice_is_refused(): void
    {
        $plan = $this->plan();
        [$company, $owner] = $this->company($plan);

        $this->expectException(ValidationException::class);

        app(SubscribeToPlan::class)->handle($owner, $company, $plan, BillingInterval::Monthly);
    }

    public function test_an_upgrade_credits_the_unused_period_and_applies_when_paid(): void
    {
        $small = $this->plan(['max_users' => 3]);
        $big = $this->plan(['price_monthly' => 300_000, 'price_yearly' => 3_000_000, 'max_users' => 10]);
        [$company, $owner, $wallet] = $this->company($small);
        $this->fund($wallet, 1_000_000);

        // Exactly half of a 30-day period is left.
        $company->subscription()->firstOrFail()->forceFill(['current_period_start' => now()->subDays(15), 'current_period_end' => now()->addDays(15)])->save();

        ['result' => $result, 'invoice' => $invoice] = app(ChangePlan::class)->handle($owner, $company, $big, BillingInterval::Monthly);

        $this->assertSame('upgrade_due', $result);
        $this->assertSame(250_000, $invoice?->amount->amount); // 300,000 less half of 100,000
        $this->assertSame($small->id, $company->subscription()->first()?->plan_id, 'The plan only changes once the difference is paid.');

        app(PayInvoice::class)->fromWallet($invoice);

        $subscription = $company->subscription()->firstOrFail();
        $this->assertSame($big->id, $subscription->plan_id);
        $this->assertSame(300_000, $subscription->price->amount);
        $this->assertSame(10, $subscription->maxUsers());
        $this->assertTrue($subscription->current_period_start?->equalTo(now()));
        $this->assertLedgerBalanced();
    }

    public function test_a_downgrade_waits_for_the_end_of_the_period_and_can_be_undone(): void
    {
        $small = $this->plan(['price_monthly' => 100_000]);
        $big = $this->plan(['price_monthly' => 300_000, 'price_yearly' => 3_000_000, 'max_users' => 10]);
        [$company, $owner] = $this->company($big);

        ['result' => $result, 'invoice' => $invoice] = app(ChangePlan::class)->handle($owner, $company, $small, BillingInterval::Monthly);

        $this->assertSame('scheduled', $result);
        $this->assertNull($invoice);
        $subscription = $company->subscription()->firstOrFail();
        $this->assertSame($big->id, $subscription->plan_id);
        $this->assertSame($small->id, $subscription->pending_plan_id);

        ['result' => $result] = app(ChangePlan::class)->handle($owner, $company, $big, BillingInterval::Monthly);

        $this->assertSame('unscheduled', $result);
        $this->assertNull($company->subscription()->first()?->pending_plan_id);
    }

    public function test_a_downgrade_that_does_not_fit_the_team_is_refused(): void
    {
        $small = $this->plan(['price_monthly' => 100_000, 'max_users' => 2]);
        $big = $this->plan(['price_monthly' => 300_000, 'price_yearly' => 3_000_000, 'max_users' => 10]);
        [$company, $owner] = $this->company($big);
        User::factory()->companyMember($company)->count(2)->create(); // owner + 2 = 3 seats

        try {
            app(ChangePlan::class)->handle($owner, $company, $small, BillingInterval::Monthly);
            $this->fail('A plan with too few seats was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('plan', $e->errors());
        }

        $this->assertNull($company->subscription()->first()?->pending_plan_id);
    }

    public function test_during_a_trial_a_plan_change_is_immediate(): void
    {
        $small = $this->plan();
        $big = $this->plan(['price_monthly' => 300_000, 'price_yearly' => 3_000_000, 'max_users' => 10]);
        [$company, $owner] = $this->company($small, SubscriptionStatus::Trialing);
        $company->subscription()->firstOrFail()->forceFill(['trial_ends_at' => now()->addDays(5), 'current_period_end' => now()->addDays(5)])->save();

        ['result' => $result] = app(ChangePlan::class)->handle($owner, $company, $big, BillingInterval::Monthly);

        $this->assertSame('switched', $result);
        $this->assertSame($big->id, $company->subscription()->first()?->plan_id);
    }

    public function test_cancelling_keeps_selling_until_the_period_ends_and_can_be_undone(): void
    {
        $plan = $this->plan();
        [$company, $owner] = $this->company($plan);

        $subscription = app(CancelSubscription::class)->handle($owner, $company);

        $this->assertNotNull($subscription->cancelled_at);
        $this->assertTrue($subscription->allowsSelling());

        $subscription = app(CancelSubscription::class)->handle($owner, $company, cancel: false);

        $this->assertNull($subscription->cancelled_at);
    }

    public function test_an_invoice_can_be_paid_at_a_gateway(): void
    {
        $plan = $this->plan();
        [$company, $owner] = $this->company();
        ['invoice' => $invoice] = app(SubscribeToPlan::class)->handle($owner, $company, $plan, BillingInterval::Monthly);

        $checkout = app(StartInvoicePayment::class)->handle($owner, $invoice, 'fake', CheckoutUrls::forGateway('fake'));

        $invoice->refresh();
        $this->assertStringStartsWith('INV', (string) $invoice->tran_id);
        $this->assertNotSame('', $checkout->redirectUrl);

        $response = $this->get(route('payments.return', ['gateway' => 'fake'] + FakeGateway::callback((string) $invoice->tran_id, $invoice->amount)));

        $response->assertRedirect(Billing::getUrl(['payment' => $invoice->tran_id], panel: 'seller', tenant: $company));
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()?->status);
        $this->assertSame(SubscriptionStatus::Active, $company->subscription()->first()?->status);
        $this->assertSame(100_000, $this->system(SystemAccount::SubscriptionRevenue)->balance->amount);
        $this->assertSame(-100_000, $this->system(SystemAccount::GatewayClearing)->balance->amount);

        // The gateway's notification arriving later changes nothing.
        $this->post(route('payments.notify', ['gateway' => 'fake']), FakeGateway::callback((string) $invoice->tran_id, $invoice->amount))->assertOk();
        $this->assertSame(100_000, $this->system(SystemAccount::SubscriptionRevenue)->balance->amount);
        $this->assertLedgerBalanced();
    }

    public function test_a_payment_through_an_older_attempt_is_credited_to_the_company_wallet(): void
    {
        $plan = $this->plan();
        [$company, $owner] = $this->company();
        ['invoice' => $invoice] = app(SubscribeToPlan::class)->handle($owner, $company, $plan, BillingInterval::Monthly);

        app(StartInvoicePayment::class)->handle($owner, $invoice, 'fake', CheckoutUrls::forGateway('fake'));
        $first = (string) $invoice->refresh()->tran_id;
        app(StartInvoicePayment::class)->handle($owner, $invoice, 'fake', CheckoutUrls::forGateway('fake'));
        $second = (string) $invoice->refresh()->tran_id;

        // The payer finishes the first tab: the money is kept for the company, the invoice stays open.
        $this->post(route('payments.notify', ['gateway' => 'fake']), FakeGateway::callback($first, $invoice->amount))->assertOk();
        $this->post(route('payments.notify', ['gateway' => 'fake']), FakeGateway::callback($first, $invoice->amount))->assertOk();

        $wallet = Wallet::query()->where('company_id', $company->id)->firstOrFail();
        $this->assertSame(100_000, $wallet->balance->amount);
        $this->assertSame(InvoiceStatus::Open, $invoice->fresh()?->status);

        // The current attempt still pays the invoice.
        $this->post(route('payments.notify', ['gateway' => 'fake']), FakeGateway::callback($second, $invoice->amount))->assertOk();
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()?->status);
        $this->assertSame(100_000, $wallet->fresh()?->balance->amount);
        $this->assertLedgerBalanced();
    }

    public function test_a_payment_of_the_wrong_amount_is_refused(): void
    {
        $plan = $this->plan();
        [$company, $owner] = $this->company();
        ['invoice' => $invoice] = app(SubscribeToPlan::class)->handle($owner, $company, $plan, BillingInterval::Monthly);
        app(StartInvoicePayment::class)->handle($owner, $invoice, 'fake', CheckoutUrls::forGateway('fake'));
        $invoice->refresh();

        $this->post(route('payments.notify', ['gateway' => 'fake']), FakeGateway::callback((string) $invoice->tran_id, $invoice->amount->minus(Money::minor(1))))
            ->assertStatus(400);

        $this->assertSame(InvoiceStatus::Open, $invoice->fresh()?->status);
    }

    public function test_only_the_owner_can_start_an_invoice_payment(): void
    {
        $plan = $this->plan();
        [$company, $owner] = $this->company();
        ['invoice' => $invoice] = app(SubscribeToPlan::class)->handle($owner, $company, $plan, BillingInterval::Monthly);
        $member = User::factory()->companyMember($company)->create();

        $this->expectException(AuthorizationException::class);

        app(StartInvoicePayment::class)->handle($member, $invoice, 'fake', CheckoutUrls::forGateway('fake'));
    }
}
