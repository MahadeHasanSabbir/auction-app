<?php

namespace Tests\Feature\Wallet;

use App\Actions\Wallet\StartDeposit;
use App\Enums\DepositStatus;
use App\Enums\PaymentOutcome;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payments\CheckoutUrls;
use App\Services\Payments\FakeGateway;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

class PaymentCallbackTest extends TestCase
{
    use InteractsWithLedger;
    use RefreshDatabase;

    private User $buyer;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPlatformAccounts();
        $this->buyer = User::factory()->create();
        $this->wallet = Wallet::factory()->create(['user_id' => $this->buyer->id]);
    }

    public function test_the_return_credits_the_deposit_and_sends_the_payer_to_the_wallet(): void
    {
        $deposit = $this->start('fake');

        $response = $this->get(route('payments.return', ['gateway' => 'fake'] + FakeGateway::callback($deposit->tran_id, $deposit->amount)));

        $response->assertRedirect(route('wallet', ['deposit' => $deposit->tran_id]));
        $this->assertSame(DepositStatus::Succeeded, $deposit->fresh()?->status);
        $this->assertSame(50_000, $this->wallet->fresh()?->balance->amount);
        $this->assertLedgerBalanced();
    }

    public function test_the_return_runs_without_a_session_so_a_cross_site_post_signs_nobody_out(): void
    {
        $deposit = $this->start('fake');

        // A POST without a CSRF token, as the gateway's page sends it.
        $response = $this->post(route('payments.return', ['gateway' => 'fake']), FakeGateway::callback($deposit->tran_id, $deposit->amount));

        $response->assertRedirect();
        $this->assertSame([], $response->headers->getCookies());
        $this->assertSame(DepositStatus::Succeeded, $deposit->fresh()?->status);
    }

    public function test_a_cancelled_payment_comes_back_with_its_outcome(): void
    {
        $deposit = $this->start('fake');

        $this->get(route('payments.return', ['gateway' => 'fake', 'outcome' => 'cancelled', 'tran_id' => $deposit->tran_id]))
            ->assertRedirect(route('wallet', ['deposit' => $deposit->tran_id, 'outcome' => 'cancelled']));

        $this->assertSame(DepositStatus::Pending, $deposit->fresh()?->status);
    }

    public function test_a_forged_return_credits_nothing(): void
    {
        $deposit = $this->start('fake');
        $callback = FakeGateway::callback($deposit->tran_id, $deposit->amount);
        $callback['signature'] = str_repeat('0', 64);

        $this->get(route('payments.return', ['gateway' => 'fake'] + $callback))->assertRedirect();

        $this->assertSame(0, $this->wallet->fresh()?->balance->amount);
    }

    public function test_notifications_answer_the_gateway(): void
    {
        $deposit = $this->start('fake');

        $this->post(route('payments.notify', ['gateway' => 'fake']), ['tran_id' => $deposit->tran_id])->assertStatus(400);
        $this->post(route('payments.notify', ['gateway' => 'fake']), FakeGateway::callback($deposit->tran_id, $deposit->amount, PaymentOutcome::Succeeded))
            ->assertOk()
            ->assertSee('OK');

        $this->assertSame(50_000, $this->wallet->fresh()?->balance->amount);
    }

    public function test_a_gateway_that_is_not_enabled_is_not_found(): void
    {
        config(['auction.payments.gateways' => ['fake']]);

        $this->post(route('payments.notify', ['gateway' => 'stripe']))->assertNotFound();
        $this->get(route('payments.return', ['gateway' => 'nope']))->assertNotFound();
    }

    public function test_a_signed_stripe_webhook_credits_the_deposit_once(): void
    {
        config([
            'auction.payments.gateways' => ['stripe'],
            'services.stripe' => ['secret' => 'sk_test_123', 'webhook_secret' => 'whsec_test'],
        ]);
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_test_abcdefghijkl', 'url' => 'https://checkout.stripe.com/x'])]);

        $deposit = $this->start('stripe');
        $payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_test_abcdefghijkl', 'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 50_000,
            'currency' => 'bdt', 'client_reference_id' => $deposit->tran_id, 'payment_intent' => 'pi_1',
        ]]], JSON_THROW_ON_ERROR);
        $at = now()->getTimestamp();
        $headers = ['Stripe-Signature' => "t={$at},v1=".hash_hmac('sha256', "{$at}.{$payload}", 'whsec_test'), 'Content-Type' => 'application/json'];

        $this->call('POST', route('payments.notify', ['gateway' => 'stripe']), server: $this->transformHeadersToServerVars($headers), content: $payload)->assertOk();
        $this->call('POST', route('payments.notify', ['gateway' => 'stripe']), server: $this->transformHeadersToServerVars($headers), content: $payload)->assertOk();

        $this->assertSame(DepositStatus::Succeeded, $deposit->fresh()?->status);
        $this->assertSame('pi_1', $deposit->fresh()?->gateway_reference);
        $this->assertSame(50_000, $this->wallet->fresh()?->balance->amount);
        $this->assertLedgerBalanced();
    }

    private function start(string $gateway): Deposit
    {
        return app(StartDeposit::class)->handle($this->buyer, Money::minor(50_000), $gateway, new CheckoutUrls(
            success: route('payments.return', ['gateway' => $gateway]),
            fail: route('payments.return', ['gateway' => $gateway, 'outcome' => 'failed']),
            cancel: route('payments.return', ['gateway' => $gateway, 'outcome' => 'cancelled']),
        ))['deposit'];
    }
}
