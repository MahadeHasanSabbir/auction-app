<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Wallet\CompleteDeposit;
use App\Http\Controllers\Controller;
use App\Services\Payments\IgnoredCallbackException;
use App\Services\Payments\InvalidCallbackException;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGateways;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Where gateways send the payer back and post their notifications. These
 * routes run without a session or CSRF check (routes/payments.php): the
 * gateway posts cross-site, the browser leaves the session cookie behind,
 * and a fresh session would sign the payer out.
 */
final class PaymentCallbackController extends Controller
{
    public function __construct(
        private readonly PaymentGateways $gateways,
        private readonly CompleteDeposit $completeDeposit,
    ) {}

    /**
     * The payer's browser coming back. Whatever happened, the payer lands on
     * the wallet page, which reads the deposit's status for itself.
     */
    public function return(Request $request, string $gateway): RedirectResponse
    {
        $driver = $this->driver($gateway);
        $hint = in_array($request->query('outcome'), ['failed', 'cancelled'], true) ? (string) $request->query('outcome') : null;
        $tranId = null;

        try {
            $tranId = $this->completeDeposit->handle($driver->verify($request))->tran_id;
        } catch (IgnoredCallbackException) {
            // Not settled yet; the notification settles it.
        } catch (InvalidCallbackException $e) {
            // A payer who cancelled at the gateway brings nothing to confirm.
            if ($hint === null) {
                Log::warning('A payment return was refused.', ['gateway' => $gateway, 'reason' => $e->getMessage()]);
            }
        }

        $tranId ??= $this->tranId($request);

        return redirect()->route('wallet', array_filter(['deposit' => $tranId, 'outcome' => $hint]));
    }

    /** Server to server: the SSLCommerz IPN or a Stripe webhook. */
    public function notify(Request $request, string $gateway): Response
    {
        $driver = $this->driver($gateway);

        try {
            $this->completeDeposit->handle($driver->verify($request));
        } catch (IgnoredCallbackException) {
            return response('Ignored');
        } catch (InvalidCallbackException $e) {
            Log::warning('A payment notification was refused.', ['gateway' => $gateway, 'reason' => $e->getMessage()]);

            return response('Refused', 400);
        }

        return response('OK');
    }

    private function driver(string $gateway): PaymentGateway
    {
        abort_unless($this->gateways->isEnabled($gateway), 404);

        return $this->gateways->get($gateway);
    }

    /** Our reference as the gateway echoed it; only used to pick what to show. */
    private function tranId(Request $request): ?string
    {
        $tranId = $request->input('tran_id');

        return is_string($tranId) && preg_match('/^[A-Z0-9]{1,64}$/', $tranId) ? $tranId : null;
    }
}
