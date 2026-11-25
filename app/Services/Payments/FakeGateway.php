<?php

namespace App\Services\Payments;

use App\Enums\PaymentOutcome;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A gateway that needs no account, for local work and tests. It never runs
 * in production (PaymentGateways refuses it there).
 *
 * Checkout "pays" at once: the payer is sent straight to the success URL
 * with the result in the query string, signed with the application key so
 * that a hand-made callback is refused. Tests build callbacks, including
 * failed and cancelled ones, with FakeGateway::callback().
 */
final class FakeGateway implements PaymentGateway
{
    private const FIELDS = ['tran_id', 'amount', 'currency', 'outcome', 'reference'];

    public function name(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return __('Test payment (no real money)');
    }

    public function checkout(CheckoutRequest $request): CheckoutSession
    {
        $reference = 'FAKE-'.Str::upper(Str::random(12));
        $query = http_build_query(self::callback($request->tranId, $request->amount, PaymentOutcome::Succeeded, $reference));
        $separator = str_contains($request->urls->success, '?') ? '&' : '?';

        return new CheckoutSession($request->urls->success.$separator.$query, $reference);
    }

    public function verify(Request $request): PaymentResult
    {
        $params = [];

        foreach (self::FIELDS as $field) {
            $value = $request->input($field);

            if (! is_string($value) || $value === '') {
                throw new InvalidCallbackException("The fake gateway callback has no [{$field}].");
            }

            $params[$field] = $value;
        }

        $signature = $request->input('signature');

        if (! is_string($signature) || ! hash_equals(self::sign($params), $signature)) {
            throw new InvalidCallbackException('The fake gateway callback is not signed correctly.');
        }

        $outcome = PaymentOutcome::tryFrom($params['outcome'])
            ?? throw new InvalidCallbackException("Unknown outcome [{$params['outcome']}].");

        if (! ctype_digit($params['amount'])) {
            throw new InvalidCallbackException("Not an amount: [{$params['amount']}].");
        }

        return new PaymentResult(
            gateway: $this->name(),
            tranId: $params['tran_id'],
            outcome: $outcome,
            amount: Money::minor((int) $params['amount'], $params['currency']),
            gatewayReference: $params['reference'],
            payload: $params,
        );
    }

    /**
     * The signed parameters the fake gateway sends back for a payment.
     *
     * @return array<string, string>
     */
    public static function callback(string $tranId, Money $amount, PaymentOutcome $outcome = PaymentOutcome::Succeeded, ?string $reference = null): array
    {
        $params = [
            'tran_id' => $tranId,
            'amount' => (string) $amount->amount,
            'currency' => $amount->currency,
            'outcome' => $outcome->value,
            'reference' => $reference ?? 'FAKE-'.Str::upper(Str::random(12)),
        ];

        return $params + ['signature' => self::sign($params)];
    }

    /** @param  array<string, string>  $params */
    private static function sign(array $params): string
    {
        ksort($params);

        return hash_hmac('sha256', http_build_query($params), 'fake-gateway|'.config('app.key'));
    }
}
