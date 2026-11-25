<?php

namespace App\Services\Payments;

use App\Enums\PaymentOutcome;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stripe Checkout, through its REST API (no SDK).
 *
 * checkout() creates a Checkout Session and sends the payer to it. Two
 * requests confirm the payment: the payer's return to the success URL
 * carries the session id, which is looked up with the secret key; and
 * Stripe's webhook posts checkout.session.* events signed with the webhook
 * secret. Either may arrive first; CompleteDeposit credits once.
 *
 * Amounts go to Stripe in minor units, so the marketplace currency must have
 * as many fraction digits in Stripe as in config('auction.currency_decimals').
 */
final class StripeGateway implements PaymentGateway
{
    /** Seconds a webhook signature stays valid, as Stripe's own libraries use. */
    private const TOLERANCE = 300;

    public function name(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return __('International card (Stripe)');
    }

    public function checkout(CheckoutRequest $request): CheckoutSession
    {
        $back = fn (string $url, string $extra) => $url.(str_contains($url, '?') ? '&' : '?').'tran_id='.urlencode($request->tranId).$extra;

        try {
            $response = $this->http()
                ->asForm()
                ->withHeaders(['Idempotency-Key' => $request->tranId])
                ->post('/checkout/sessions', [
                    'mode' => 'payment',
                    'client_reference_id' => $request->tranId,
                    'customer_email' => $request->customerEmail,
                    // Stripe fills in {CHECKOUT_SESSION_ID} itself.
                    'success_url' => $back($request->urls->success, '&session_id={CHECKOUT_SESSION_ID}'),
                    'cancel_url' => $back($request->urls->cancel, ''),
                    'line_items' => [[
                        'quantity' => 1,
                        'price_data' => [
                            'currency' => strtolower($request->amount->currency),
                            'unit_amount' => $request->amount->amount,
                            'product_data' => ['name' => $request->description],
                        ],
                    ]],
                    'metadata' => ['tran_id' => $request->tranId],
                    'payment_intent_data' => ['metadata' => ['tran_id' => $request->tranId]],
                ]);
        } catch (ConnectionException $e) {
            throw new PaymentGatewayException('Stripe could not be reached.', previous: $e);
        }

        $url = $response->json('url');

        if (! $response->successful() || ! is_string($url) || $url === '') {
            throw new PaymentGatewayException('Stripe refused the payment: '.($response->json('error.message') ?: $response->status()));
        }

        return new CheckoutSession($url, (string) $response->json('id'));
    }

    public function verify(Request $request): PaymentResult
    {
        if ($request->hasHeader('Stripe-Signature')) {
            return $this->fromWebhook($request);
        }

        $sessionId = $request->query('session_id');

        if (! is_string($sessionId) || ! preg_match('/^cs_[A-Za-z0-9_]{10,200}$/', $sessionId)) {
            throw new InvalidCallbackException('The Stripe return has no checkout session id.');
        }

        try {
            $response = $this->http()->get('/checkout/sessions/'.$sessionId);
        } catch (ConnectionException $e) {
            throw new InvalidCallbackException('Stripe could not be reached to confirm a payment.', previous: $e);
        }

        $session = $response->json();

        if (! $response->successful() || ! is_array($session)) {
            throw new InvalidCallbackException("Stripe does not know checkout session [{$sessionId}].");
        }

        $outcome = match (true) {
            ($session['status'] ?? null) === 'complete' && in_array($session['payment_status'] ?? null, ['paid', 'no_payment_required'], true) => PaymentOutcome::Succeeded,
            ($session['status'] ?? null) === 'expired' => PaymentOutcome::Cancelled,
            default => throw new IgnoredCallbackException("Checkout session [{$sessionId}] is not paid yet."),
        };

        return $this->result($session, $outcome);
    }

    private function fromWebhook(Request $request): PaymentResult
    {
        $payload = $request->getContent();
        $this->checkSignature($payload, (string) $request->header('Stripe-Signature'));

        $event = json_decode($payload, true);
        $session = is_array($event) ? ($event['data']['object'] ?? null) : null;

        if (! is_array($event) || ! is_array($session)) {
            throw new InvalidCallbackException('The Stripe webhook is not a readable event.');
        }

        $type = (string) ($event['type'] ?? '');

        $outcome = match ($type) {
            'checkout.session.completed' => ($session['payment_status'] ?? null) === 'unpaid'
                // Paid later by a delayed method; async_payment_* follows.
                ? throw new IgnoredCallbackException('The checkout session waits for a delayed payment.')
                : PaymentOutcome::Succeeded,
            'checkout.session.async_payment_succeeded' => PaymentOutcome::Succeeded,
            'checkout.session.async_payment_failed' => PaymentOutcome::Failed,
            'checkout.session.expired' => PaymentOutcome::Cancelled,
            default => throw new IgnoredCallbackException("Stripe event [{$type}] is not used."),
        };

        return $this->result($session, $outcome);
    }

    /**
     * Stripe signs "{timestamp}.{body}" with HMAC-SHA256; the header carries
     * t=… and one or more v1=… signatures.
     */
    private function checkSignature(string $payload, string $header): void
    {
        $secret = config('services.stripe.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw new InvalidCallbackException('STRIPE_WEBHOOK_SECRET is not set, so no webhook can be trusted.');
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw new InvalidCallbackException('The Stripe-Signature header is malformed.');
        }

        if (abs(now()->getTimestamp() - $timestamp) > self::TOLERANCE) {
            throw new InvalidCallbackException('The Stripe webhook is too old or from the future.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw new InvalidCallbackException('The Stripe webhook signature does not match.');
    }

    /** @param  array<string, mixed>  $session */
    private function result(array $session, PaymentOutcome $outcome): PaymentResult
    {
        $tranId = $session['client_reference_id'] ?? $session['metadata']['tran_id'] ?? null;

        if (! is_string($tranId) || $tranId === '') {
            throw new IgnoredCallbackException('The checkout session was not started by this marketplace.');
        }

        $total = $session['amount_total'] ?? null;
        $currency = $session['currency'] ?? null;

        if (! is_int($total) || ! is_string($currency)) {
            throw new InvalidCallbackException("Checkout session for [{$tranId}] has no amount.");
        }

        $intent = $session['payment_intent'] ?? null;

        return new PaymentResult(
            gateway: $this->name(),
            tranId: $tranId,
            outcome: $outcome,
            amount: Money::minor($total, strtoupper($currency)),
            gatewayReference: is_string($intent) ? $intent : (string) ($session['id'] ?? ''),
            payload: array_intersect_key($session, array_flip([
                'id', 'status', 'payment_status', 'amount_total', 'currency', 'payment_intent', 'client_reference_id',
            ])),
        );
    }

    private function http(): PendingRequest
    {
        $secret = config('services.stripe.secret');

        if (! is_string($secret) || $secret === '') {
            throw new PaymentGatewayException('Stripe is not configured (STRIPE_SECRET).');
        }

        return Http::baseUrl('https://api.stripe.com/v1')->withToken($secret)->acceptJson()->timeout(20);
    }
}
