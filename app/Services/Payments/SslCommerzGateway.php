<?php

namespace App\Services\Payments;

use App\Enums\PaymentOutcome;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * SSLCommerz hosted checkout (cards, bKash, Nagad, Rocket, internet banking).
 *
 * checkout() opens a session and sends the payer to SSLCommerz. The payer's
 * browser comes back with a POST to the success, fail or cancel URL, and
 * SSLCommerz separately posts the same fields to the IPN URL. Neither is
 * trusted: a success is confirmed by asking the validation API about its
 * val_id. A failure or cancellation is accepted as posted; forging one can
 * only mark a pending deposit failed, and a later confirmed payment is still
 * credited.
 */
final class SslCommerzGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'sslcommerz';
    }

    public function label(): string
    {
        return __('Card, bKash, Nagad or Rocket (SSLCommerz)');
    }

    public function checkout(CheckoutRequest $request): CheckoutSession
    {
        try {
            $response = $this->http()->asForm()->post('/gwprocess/v4/api.php', $this->credentials() + [
                'total_amount' => $request->amount->toDecimal(),
                'currency' => $request->amount->currency,
                'tran_id' => $request->tranId,
                'success_url' => $request->urls->success,
                'fail_url' => $request->urls->fail,
                'cancel_url' => $request->urls->cancel,
                'ipn_url' => $request->urls->notify,
                'cus_name' => $request->customerName,
                'cus_email' => $request->customerEmail,
                'cus_phone' => $request->customerPhone ?? 'N/A',
                'cus_add1' => 'N/A',
                'cus_city' => 'N/A',
                'cus_country' => 'Bangladesh',
                'shipping_method' => 'NO',
                'num_of_item' => 1,
                'product_name' => $request->description,
                'product_category' => 'Wallet',
                'product_profile' => 'non-physical-goods',
                'value_a' => $request->tranId,
            ]);
        } catch (ConnectionException $e) {
            throw new PaymentGatewayException('SSLCommerz could not be reached.', previous: $e);
        }

        $url = $response->json('GatewayPageURL');

        if (! $response->successful() || $response->json('status') !== 'SUCCESS' || ! is_string($url) || $url === '') {
            throw new PaymentGatewayException('SSLCommerz refused the payment: '.($response->json('failedreason') ?: $response->status()));
        }

        $session = $response->json('sessionkey');

        return new CheckoutSession($url, is_string($session) ? $session : null);
    }

    public function verify(Request $request): PaymentResult
    {
        $tranId = $request->input('tran_id');

        if (! is_string($tranId) || $tranId === '') {
            throw new InvalidCallbackException('The SSLCommerz callback has no tran_id.');
        }

        $status = strtoupper((string) $request->input('status'));

        if ($status === 'FAILED' || $status === 'CANCELLED') {
            return new PaymentResult(
                gateway: $this->name(),
                tranId: $tranId,
                outcome: $status === 'FAILED' ? PaymentOutcome::Failed : PaymentOutcome::Cancelled,
                amount: Money::zero(),
                payload: $request->only(['tran_id', 'status', 'error', 'bank_tran_id']),
            );
        }

        $valId = $request->input('val_id');

        if (! is_string($valId) || $valId === '') {
            throw new InvalidCallbackException("The SSLCommerz callback for [{$tranId}] has no val_id.");
        }

        return $this->validate($valId, $tranId);
    }

    /** Asks SSLCommerz whether the payment behind a val_id really happened. */
    private function validate(string $valId, string $tranId): PaymentResult
    {
        try {
            $response = $this->http()->get('/validator/api/validationserverAPI.php', $this->credentials() + [
                'val_id' => $valId,
                'format' => 'json',
            ]);
        } catch (ConnectionException $e) {
            throw new InvalidCallbackException('SSLCommerz validation could not be reached.', previous: $e);
        }

        $data = $response->json();

        if (! $response->successful() || ! is_array($data)) {
            throw new InvalidCallbackException("SSLCommerz validation failed for [{$tranId}] with HTTP {$response->status()}.");
        }

        if (! in_array($data['status'] ?? null, ['VALID', 'VALIDATED'], true)) {
            throw new InvalidCallbackException("SSLCommerz does not confirm [{$tranId}]: ".($data['status'] ?? 'no status').'.');
        }

        if (($data['tran_id'] ?? null) !== $tranId) {
            throw new InvalidCallbackException("SSLCommerz validated [{$valId}] for another transaction.");
        }

        // SSLCommerz flags payments it considers risky; they wait for staff.
        if ((string) ($data['risk_level'] ?? '0') === '1') {
            throw new InvalidCallbackException("SSLCommerz flagged [{$tranId}] as risky: ".($data['risk_title'] ?? '').'.');
        }

        // currency_amount/currency_type are what was asked for; amount is in BDT.
        $currency = (string) ($data['currency_type'] ?? $data['currency'] ?? '');
        $decimal = (string) ($data['currency_amount'] ?? $data['amount'] ?? '');

        // "1000.0000" carries more fraction digits than the currency has.
        if (str_contains($decimal, '.')) {
            $decimal = rtrim(rtrim($decimal, '0'), '.');
        }

        try {
            $amount = Money::parse($decimal, $currency);
        } catch (InvalidArgumentException $e) {
            throw new InvalidCallbackException("SSLCommerz reported an unreadable amount for [{$tranId}].", previous: $e);
        }

        return new PaymentResult(
            gateway: $this->name(),
            tranId: $tranId,
            outcome: PaymentOutcome::Succeeded,
            amount: $amount,
            gatewayReference: is_string($data['bank_tran_id'] ?? null) ? $data['bank_tran_id'] : $valId,
            payload: array_intersect_key($data, array_flip([
                'status', 'tran_id', 'val_id', 'amount', 'currency', 'currency_type', 'currency_amount',
                'store_amount', 'bank_tran_id', 'card_type', 'card_brand', 'tran_date', 'risk_level',
            ])),
        );
    }

    private function http(): PendingRequest
    {
        $base = config('services.sslcommerz.sandbox')
            ? 'https://sandbox.sslcommerz.com'
            : 'https://securepay.sslcommerz.com';

        return Http::baseUrl($base)->acceptJson()->timeout(20);
    }

    /** @return array{store_id: string, store_passwd: string} */
    private function credentials(): array
    {
        $id = config('services.sslcommerz.store_id');
        $password = config('services.sslcommerz.store_password');

        if (! is_string($id) || $id === '' || ! is_string($password) || $password === '') {
            throw new PaymentGatewayException('SSLCommerz is not configured (SSLCOMMERZ_STORE_ID, SSLCOMMERZ_STORE_PASSWORD).');
        }

        return ['store_id' => $id, 'store_passwd' => $password];
    }
}
