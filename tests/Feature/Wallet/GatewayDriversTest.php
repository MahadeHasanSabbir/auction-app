<?php

namespace Tests\Feature\Wallet;

use App\Enums\PaymentOutcome;
use App\Services\Payments\CheckoutRequest;
use App\Services\Payments\CheckoutUrls;
use App\Services\Payments\IgnoredCallbackException;
use App\Services\Payments\InvalidCallbackException;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\SslCommerzGateway;
use App\Services\Payments\StripeGateway;
use App\Support\Money;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The SSLCommerz and Stripe drivers against faked HTTP; no database needed. */
class GatewayDriversTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sslcommerz' => ['store_id' => 'store', 'store_password' => 'secret', 'sandbox' => true],
            'services.stripe' => ['secret' => 'sk_test_123', 'webhook_secret' => 'whsec_test'],
        ]);
        Http::preventStrayRequests();
    }

    public function test_sslcommerz_checkout_opens_a_session(): void
    {
        Http::fake(['sandbox.sslcommerz.com/gwprocess/*' => Http::response([
            'status' => 'SUCCESS', 'GatewayPageURL' => 'https://sandbox.sslcommerz.com/pay/abc', 'sessionkey' => 'SESSION1',
        ])]);

        $session = (new SslCommerzGateway)->checkout($this->checkoutRequest());

        $this->assertSame('https://sandbox.sslcommerz.com/pay/abc', $session->redirectUrl);
        $this->assertSame('SESSION1', $session->gatewayReference);
        Http::assertSent(fn (ClientRequest $request) => $request['total_amount'] === '1250.50'
            && $request['tran_id'] === 'DEP1'
            && $request['store_passwd'] === 'secret'
            && $request['ipn_url'] === 'https://shop.test/notify');
    }

    public function test_sslcommerz_checkout_failure_is_reported(): void
    {
        Http::fake(['*' => Http::response(['status' => 'FAILED', 'failedreason' => 'Store is inactive'])]);

        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionMessage('Store is inactive');

        (new SslCommerzGateway)->checkout($this->checkoutRequest());
    }

    public function test_sslcommerz_success_is_confirmed_with_the_validation_api(): void
    {
        Http::fake(['sandbox.sslcommerz.com/validator/*' => Http::response([
            'status' => 'VALID', 'tran_id' => 'DEP1', 'val_id' => 'VAL1', 'amount' => '1250.50', 'currency' => 'BDT',
            'currency_type' => 'BDT', 'currency_amount' => '1250.5000', 'bank_tran_id' => 'BANK1', 'risk_level' => '0',
        ])]);

        $result = (new SslCommerzGateway)->verify(Request::create('/return', 'POST', ['tran_id' => 'DEP1', 'val_id' => 'VAL1', 'status' => 'VALID', 'amount' => '9999999']));

        $this->assertSame(PaymentOutcome::Succeeded, $result->outcome);
        $this->assertSame(125_050, $result->amount->amount);
        $this->assertSame('BANK1', $result->gatewayReference);
        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'val_id=VAL1'));
    }

    public function test_sslcommerz_refuses_what_its_api_does_not_confirm(): void
    {
        $cases = [
            ['status' => 'INVALID_TRANSACTION'],
            ['status' => 'VALID', 'tran_id' => 'OTHER', 'amount' => '10.00', 'currency' => 'BDT'],
            ['status' => 'VALID', 'tran_id' => 'DEP1', 'amount' => '10.00', 'currency' => 'BDT', 'risk_level' => '1'],
        ];

        foreach ($cases as $response) {
            Http::fake(['*' => Http::response($response)]);

            try {
                (new SslCommerzGateway)->verify(Request::create('/return', 'POST', ['tran_id' => 'DEP1', 'val_id' => 'VAL1', 'status' => 'VALID']));
                $this->fail('Accepted: '.json_encode($response));
            } catch (InvalidCallbackException) {
            }
        }

        $this->expectException(InvalidCallbackException::class);
        (new SslCommerzGateway)->verify(Request::create('/return', 'POST', ['tran_id' => 'DEP1', 'status' => 'VALID']));
    }

    public function test_sslcommerz_failure_and_cancellation_need_no_api_call(): void
    {
        $failed = (new SslCommerzGateway)->verify(Request::create('/return', 'POST', ['tran_id' => 'DEP1', 'status' => 'FAILED']));
        $cancelled = (new SslCommerzGateway)->verify(Request::create('/return', 'POST', ['tran_id' => 'DEP1', 'status' => 'CANCELLED']));

        $this->assertSame(PaymentOutcome::Failed, $failed->outcome);
        $this->assertSame(PaymentOutcome::Cancelled, $cancelled->outcome);
        Http::assertNothingSent();
    }

    public function test_stripe_checkout_creates_a_session_in_minor_units(): void
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_abcdefghijkl', 'url' => 'https://checkout.stripe.com/c/pay/x'])]);

        $session = (new StripeGateway)->checkout($this->checkoutRequest());

        $this->assertSame('https://checkout.stripe.com/c/pay/x', $session->redirectUrl);
        $this->assertSame('cs_test_abcdefghijkl', $session->gatewayReference);
        Http::assertSent(fn (ClientRequest $request) => $request->hasHeader('Authorization', 'Bearer sk_test_123')
            && $request->hasHeader('Idempotency-Key', 'DEP1')
            && $request['line_items'][0]['price_data']['unit_amount'] === 125_050
            && $request['line_items'][0]['price_data']['currency'] === 'bdt'
            && $request['client_reference_id'] === 'DEP1'
            && $request['success_url'] === 'https://shop.test/return?tran_id=DEP1&session_id={CHECKOUT_SESSION_ID}');
    }

    public function test_stripe_return_is_confirmed_by_looking_the_session_up(): void
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_test_abcdefghijkl' => Http::response($this->stripeSession())]);

        $result = (new StripeGateway)->verify(Request::create('/return?session_id=cs_test_abcdefghijkl'));

        $this->assertSame(PaymentOutcome::Succeeded, $result->outcome);
        $this->assertSame('DEP1', $result->tranId);
        $this->assertSame(125_050, $result->amount->amount);
        $this->assertSame('BDT', $result->amount->currency);
        $this->assertSame('pi_123', $result->gatewayReference);
    }

    public function test_stripe_return_for_an_open_session_waits(): void
    {
        Http::fake(['*' => Http::response($this->stripeSession(['status' => 'open', 'payment_status' => 'unpaid']))]);

        $this->expectException(IgnoredCallbackException::class);

        (new StripeGateway)->verify(Request::create('/return?session_id=cs_test_abcdefghijkl'));
    }

    public function test_stripe_webhooks_are_checked_against_their_signature(): void
    {
        $payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => $this->stripeSession()]], JSON_THROW_ON_ERROR);

        $result = (new StripeGateway)->verify($this->webhook($payload, $this->sign($payload)));
        $this->assertSame(PaymentOutcome::Succeeded, $result->outcome);

        $refused = [
            'wrong secret' => $this->sign($payload, secret: 'whsec_other'),
            'too old' => $this->sign($payload, at: now()->getTimestamp() - 301),
            'malformed' => 'v1=abc',
        ];

        foreach ($refused as $case => $header) {
            try {
                (new StripeGateway)->verify($this->webhook($payload, $header));
                $this->fail("Accepted a webhook: {$case}.");
            } catch (InvalidCallbackException) {
            }
        }

        Http::assertNothingSent();
    }

    public function test_stripe_webhook_events_map_to_outcomes(): void
    {
        $outcome = fn (string $type) => (new StripeGateway)->verify($this->signedWebhook($type))->outcome;

        $this->assertSame(PaymentOutcome::Succeeded, $outcome('checkout.session.async_payment_succeeded'));
        $this->assertSame(PaymentOutcome::Failed, $outcome('checkout.session.async_payment_failed'));
        $this->assertSame(PaymentOutcome::Cancelled, $outcome('checkout.session.expired'));

        $this->expectException(IgnoredCallbackException::class);
        $outcome('customer.created');
    }

    private function checkoutRequest(): CheckoutRequest
    {
        return new CheckoutRequest(
            tranId: 'DEP1',
            amount: Money::minor(125_050),
            description: 'Wallet top-up',
            customerName: 'Ayesha',
            customerEmail: 'ayesha@example.com',
            urls: new CheckoutUrls('https://shop.test/return', 'https://shop.test/return?outcome=failed', 'https://shop.test/return?outcome=cancelled', 'https://shop.test/notify'),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function stripeSession(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'cs_test_abcdefghijkl',
            'object' => 'checkout.session',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 125_050,
            'currency' => 'bdt',
            'client_reference_id' => 'DEP1',
            'payment_intent' => 'pi_123',
        ];
    }

    private function signedWebhook(string $type): Request
    {
        $payload = json_encode(['type' => $type, 'data' => ['object' => $this->stripeSession()]], JSON_THROW_ON_ERROR);

        return $this->webhook($payload, $this->sign($payload));
    }

    private function webhook(string $payload, string $signature): Request
    {
        return Request::create('/notify', 'POST', server: ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], content: $payload);
    }

    private function sign(string $payload, string $secret = 'whsec_test', ?int $at = null): string
    {
        $at ??= now()->getTimestamp();

        return "t={$at},v1=".hash_hmac('sha256', "{$at}.{$payload}", $secret);
    }
}
