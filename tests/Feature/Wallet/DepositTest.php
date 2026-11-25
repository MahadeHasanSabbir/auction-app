<?php

namespace Tests\Feature\Wallet;

use App\Actions\Wallet\CompleteDeposit;
use App\Actions\Wallet\StartDeposit;
use App\Enums\DepositStatus;
use App\Enums\FeeChargeType;
use App\Enums\LedgerComponent;
use App\Enums\PaymentOutcome;
use App\Enums\SystemAccount;
use App\Models\Deposit;
use App\Models\FeeRule;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payments\CheckoutSession;
use App\Services\Payments\CheckoutUrls;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\InvalidCallbackException;
use App\Services\Payments\PaymentGateways;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

class DepositTest extends TestCase
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

    public function test_a_deposit_is_charged_in_full_and_credited_net_of_charges(): void
    {
        $gatewayRule = FeeRule::factory()->create(['charge_type' => FeeChargeType::Gateway, 'gateway' => 'fake', 'percent_bps' => 250]);
        FeeRule::factory()->create(['charge_type' => FeeChargeType::Service, 'percent_bps' => 100]);

        ['deposit' => $deposit, 'checkout' => $checkout] = $this->start(100_000);

        $this->assertSame(DepositStatus::Pending, $deposit->status);
        $this->assertSame(2_500, $deposit->gateway_charge->amount);
        $this->assertSame(1_000, $deposit->service_charge->amount);
        $this->assertSame(96_500, $deposit->net_amount->amount);
        $this->assertSame($gatewayRule->id, $deposit->gateway_fee_rule_id);
        $this->assertStringStartsWith('https://shop.test/deposits/return?', $checkout->redirectUrl);
        $this->assertSame(0, $this->wallet->fresh()?->balance->amount, 'Nothing is credited before the gateway confirms.');

        $this->complete($this->callbackFrom($checkout->redirectUrl));

        $deposit->refresh();
        $this->assertSame(DepositStatus::Succeeded, $deposit->status);
        $this->assertNotNull($deposit->completed_at);
        $this->assertSame(96_500, $this->wallet->fresh()?->balance->amount);
        $this->assertSame(2_500, $this->system(SystemAccount::GatewayCharges)->balance->amount);
        $this->assertSame(1_000, $this->system(SystemAccount::ServiceRevenue)->balance->amount);
        $this->assertSame(-100_000, $this->system(SystemAccount::GatewayClearing)->balance->amount);

        $charge = $deposit->ledgerTransaction()->sole()->entries()->where('component', LedgerComponent::GatewayCharge)->sole();
        $this->assertSame($gatewayRule->id, $charge->fee_rule_id);
        $this->assertLedgerBalanced();
    }

    public function test_a_replayed_callback_credits_once(): void
    {
        ['checkout' => $checkout] = $this->start(50_000);
        $callback = $this->callbackFrom($checkout->redirectUrl);

        $this->complete($callback);
        $this->complete($callback);
        $this->complete($callback);

        $this->assertSame(50_000, $this->wallet->fresh()?->balance->amount);
        $this->assertSame(1, $this->wallet->entries()->count());
        $this->assertLedgerBalanced();
    }

    public function test_a_payment_for_a_different_amount_is_refused(): void
    {
        ['deposit' => $deposit] = $this->start(50_000);

        try {
            $this->complete(FakeGateway::callback($deposit->tran_id, Money::minor(5_000)));
            $this->fail('A mismatched amount was credited.');
        } catch (InvalidCallbackException) {
        }

        $this->assertSame(DepositStatus::Pending, $deposit->fresh()?->status);
        $this->assertSame(0, $this->wallet->fresh()?->balance->amount);
        $this->assertLedgerBalanced();
    }

    public function test_a_failed_payment_credits_nothing_but_a_later_success_does(): void
    {
        ['deposit' => $deposit] = $this->start(50_000);

        $this->complete(FakeGateway::callback($deposit->tran_id, $deposit->amount, PaymentOutcome::Cancelled));

        $this->assertSame(DepositStatus::Cancelled, $deposit->fresh()?->status);
        $this->assertSame(0, $this->wallet->fresh()?->balance->amount);

        // A failure arriving afterwards does not change a cancelled deposit…
        $this->complete(FakeGateway::callback($deposit->tran_id, $deposit->amount, PaymentOutcome::Failed));
        $this->assertSame(DepositStatus::Cancelled, $deposit->fresh()?->status);

        // …but a confirmed payment is credited: the gateway has the money.
        $this->complete(FakeGateway::callback($deposit->tran_id, $deposit->amount));
        $this->assertSame(DepositStatus::Succeeded, $deposit->fresh()?->status);
        $this->assertSame(50_000, $this->wallet->fresh()?->balance->amount);
        $this->assertLedgerBalanced();
    }

    public function test_a_tampered_callback_is_refused(): void
    {
        ['deposit' => $deposit] = $this->start(50_000);

        $callback = FakeGateway::callback($deposit->tran_id, $deposit->amount);
        $callback['amount'] = '5000000';

        $this->expectException(InvalidCallbackException::class);

        $this->complete($callback);
    }

    public function test_a_callback_for_an_unknown_deposit_is_refused(): void
    {
        $this->expectException(InvalidCallbackException::class);

        $this->complete(FakeGateway::callback('DEP-NOPE', Money::minor(1_000)));
    }

    public function test_the_amount_must_be_within_the_limits(): void
    {
        config(['auction.payments.deposit_min' => 10_000, 'auction.payments.deposit_max' => 1_000_000]);

        foreach ([9_999, 1_000_001] as $amount) {
            try {
                $this->start($amount);
                $this->fail("A deposit of {$amount} was accepted.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('amount', $e->errors());
            }
        }

        $this->assertSame(0, Deposit::query()->count());
    }

    public function test_the_charges_must_leave_something_to_credit(): void
    {
        FeeRule::factory()->create(['percent_bps' => 0, 'fixed_amount' => 20_000]);

        $this->expectException(ValidationException::class);

        $this->start(20_000);
    }

    public function test_only_enabled_gateways_are_offered(): void
    {
        config(['auction.payments.gateways' => ['fake']]);

        $this->expectException(ValidationException::class);

        app(StartDeposit::class)->handle($this->buyer, Money::minor(50_000), 'sslcommerz', $this->urls());
    }

    public function test_the_fake_gateway_is_never_offered_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertSame([], app(PaymentGateways::class)->enabled());

        $this->expectException(InvalidArgumentException::class);
        app(PaymentGateways::class)->get('fake');
    }

    public function test_only_buyers_top_up(): void
    {
        $member = User::factory()->companyMember()->create();

        $this->expectException(LogicException::class);

        app(StartDeposit::class)->handle($member, Money::minor(50_000), 'fake', $this->urls());
    }

    /** @return array{deposit: Deposit, checkout: CheckoutSession} */
    private function start(int $amount): array
    {
        return app(StartDeposit::class)->handle($this->buyer, Money::minor($amount), 'fake', $this->urls());
    }

    /** @param  array<string, string>  $params */
    private function complete(array $params): Deposit
    {
        $result = app(PaymentGateways::class)->get('fake')->verify(Request::create('/deposits/return', 'GET', $params));

        return app(CompleteDeposit::class)->handle($result);
    }

    /** @return array<string, string> */
    private function callbackFrom(string $redirectUrl): array
    {
        parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $params);

        /** @var array<string, string> $params */
        return $params;
    }

    private function urls(): CheckoutUrls
    {
        return new CheckoutUrls(
            success: 'https://shop.test/deposits/return',
            fail: 'https://shop.test/deposits/return?failed=1',
            cancel: 'https://shop.test/deposits/return?cancelled=1',
        );
    }
}
