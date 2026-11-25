<?php

namespace Tests\Feature\Wallet;

use App\Actions\Wallet\ApproveWithdrawal;
use App\Actions\Wallet\CancelWithdrawal;
use App\Actions\Wallet\PayWithdrawal;
use App\Actions\Wallet\RejectWithdrawal;
use App\Actions\Wallet\RequestWithdrawal;
use App\Enums\FeeChargeType;
use App\Enums\FeeUseCase;
use App\Enums\HoldStatus;
use App\Enums\LedgerComponent;
use App\Enums\PayoutMethod;
use App\Enums\SystemAccount;
use App\Enums\WithdrawalStatus;
use App\Models\Company;
use App\Models\FeeRule;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Services\Ledger\LedgerVerifier;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

class WithdrawalTest extends TestCase
{
    use InteractsWithLedger;
    use RefreshDatabase;

    private User $buyer;

    private Wallet $wallet;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPlatformAccounts();
        $this->buyer = User::factory()->create();
        $this->wallet = $this->fund(Wallet::factory()->create(['user_id' => $this->buyer->id]), 1_000_000);
        $this->staff = User::factory()->platformStaff()->create();
        config(['auction.payments.withdrawal_min' => 50_000]);
    }

    public function test_a_request_holds_the_amount_and_quotes_the_charges(): void
    {
        FeeRule::factory()->create(['use_case' => FeeUseCase::Withdrawal, 'charge_type' => FeeChargeType::Gateway, 'percent_bps' => 150, 'min_amount' => 1_000]);
        FeeRule::factory()->create(['use_case' => FeeUseCase::Withdrawal, 'charge_type' => FeeChargeType::Service, 'percent_bps' => 50]);

        $withdrawal = $this->request(200_000);

        $this->assertSame(WithdrawalStatus::Pending, $withdrawal->status);
        $this->assertSame(3_000, $withdrawal->gateway_charge->amount);
        $this->assertSame(1_000, $withdrawal->service_charge->amount);
        $this->assertSame(196_000, $withdrawal->net_amount->amount);
        $this->assertSame('bKash ••5678', $withdrawal->destinationSummary());

        $this->wallet->refresh();
        $this->assertSame(1_000_000, $this->wallet->balance->amount);
        $this->assertSame(800_000, $this->wallet->available()->amount);
        $this->assertLedgerBalanced();
    }

    public function test_the_destination_is_stored_encrypted(): void
    {
        $withdrawal = $this->request(100_000);

        $raw = (string) DB::table('withdrawal_requests')->where('id', $withdrawal->id)->value('destination');

        $this->assertStringNotContainsString('01712345678', $raw);
        $this->assertSame('01712345678', $withdrawal->fresh()?->destination['account_number']);
    }

    public function test_more_than_is_available_is_refused_and_nothing_is_recorded(): void
    {
        try {
            $this->request(1_000_001);
            $this->fail('An overdraft was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->assertSame(0, WithdrawalRequest::query()->count());
        $this->assertSame(0, $this->wallet->fresh()?->held->amount);
    }

    public function test_the_amount_must_reach_the_minimum_and_cover_the_charges(): void
    {
        foreach ([49_999] as $amount) {
            try {
                $this->request($amount);
                $this->fail('A withdrawal below the minimum was accepted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('amount', $e->errors());
            }
        }

        FeeRule::factory()->create(['use_case' => FeeUseCase::Withdrawal, 'percent_bps' => 0, 'fixed_amount' => 60_000]);

        $this->expectException(ValidationException::class);
        $this->request(60_000);
    }

    public function test_destination_details_are_validated_per_method(): void
    {
        try {
            $this->request(100_000, PayoutMethod::Nagad, ['account_name' => 'A', 'account_number' => '12345']);
            $this->fail('A bad mobile number was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('account_number', $e->errors());
        }

        try {
            $this->request(100_000, PayoutMethod::Bank, ['account_name' => 'A', 'account_number' => '1234567890']);
            $this->fail('A bank account without a bank was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('bank_name', $e->errors());
            $this->assertArrayHasKey('branch', $e->errors());
        }

        $bank = $this->request(100_000, PayoutMethod::Bank, [
            'account_name' => 'A', 'account_number' => '1234567890', 'bank_name' => 'City Bank', 'branch' => 'Banani', 'extra' => 'dropped',
        ]);

        $this->assertSame(['account_name', 'account_number', 'bank_name', 'branch'], array_keys($bank->destination));
    }

    public function test_only_the_owner_of_a_wallet_withdraws_from_it(): void
    {
        $this->expectException(AuthorizationException::class);

        app(RequestWithdrawal::class)->handle(User::factory()->create(), $this->wallet, Money::minor(100_000), PayoutMethod::Bkash, $this->mobile());
    }

    public function test_a_company_owner_withdraws_from_the_company_wallet_but_a_member_cannot(): void
    {
        $company = Company::factory()->create();
        $wallet = $this->fund(Wallet::factory()->forCompany($company)->create(), 500_000);
        $owner = User::factory()->companyOwner($company)->create();
        $member = User::factory()->companyMember($company)->create();

        $withdrawal = app(RequestWithdrawal::class)->handle($owner, $wallet, Money::minor(100_000), PayoutMethod::Bkash, $this->mobile());
        $this->assertSame($wallet->id, $withdrawal->wallet_id);

        $this->expectException(AuthorizationException::class);
        app(RequestWithdrawal::class)->handle($member, $wallet, Money::minor(100_000), PayoutMethod::Bkash, $this->mobile());
    }

    public function test_the_requester_can_cancel_while_pending_and_the_hold_is_released(): void
    {
        $withdrawal = $this->request(300_000);

        app(CancelWithdrawal::class)->handle($this->buyer, $withdrawal);

        $this->assertSame(WithdrawalStatus::Cancelled, $withdrawal->status);
        $this->assertSame(0, $this->wallet->fresh()?->held->amount);
        $this->assertSame(HoldStatus::Released, $withdrawal->holds()->sole()->status);
        $this->assertLedgerBalanced();

        // Nobody else, and not twice.
        $this->expectException(AuthorizationException::class);
        app(CancelWithdrawal::class)->handle($this->buyer, $withdrawal);
    }

    public function test_an_approved_withdrawal_can_no_longer_be_cancelled(): void
    {
        $withdrawal = app(ApproveWithdrawal::class)->handle($this->staff, $this->request(300_000));

        $this->expectException(AuthorizationException::class);
        app(CancelWithdrawal::class)->handle($this->buyer, $withdrawal);
    }

    public function test_paying_out_moves_the_money_and_records_who_did_it(): void
    {
        $gateway = FeeRule::factory()->create(['use_case' => FeeUseCase::Withdrawal, 'charge_type' => FeeChargeType::Gateway, 'percent_bps' => 150]);
        $withdrawal = $this->request(200_000);

        app(ApproveWithdrawal::class)->handle($this->staff, $withdrawal);
        app(PayWithdrawal::class)->handle($this->staff, $withdrawal, ' BK-123 ');

        $withdrawal->refresh();
        $this->assertSame(WithdrawalStatus::Paid, $withdrawal->status);
        $this->assertSame('BK-123', $withdrawal->payout_reference);
        $this->assertSame($this->staff->id, $withdrawal->reviewed_by);
        $this->assertNotNull($withdrawal->paid_at);

        $this->wallet->refresh();
        $this->assertSame(800_000, $this->wallet->balance->amount);
        $this->assertSame(0, $this->wallet->held->amount);
        $this->assertSame(3_000, $this->system(SystemAccount::GatewayCharges)->balance->amount);
        $this->assertSame(197_000, $this->system(SystemAccount::PayoutClearing)->balance->amount);
        $this->assertSame(HoldStatus::Captured, $withdrawal->holds()->sole()->status);

        $charge = $withdrawal->ledgerTransaction()->sole()->entries()->where('component', LedgerComponent::GatewayCharge)->sole();
        $this->assertSame($gateway->id, $charge->fee_rule_id);
        $this->assertLedgerBalanced();

        $this->assertSame(['approved', 'paid'], Activity::query()->where('subject_id', $withdrawal->id)->orderBy('id')->pluck('event')->all());
        $this->assertSame($this->staff->id, Activity::query()->where('event', 'paid')->sole()->causer_id);
    }

    public function test_a_withdrawal_is_approved_before_it_is_paid_and_paid_once(): void
    {
        $withdrawal = $this->request(200_000);

        try {
            app(PayWithdrawal::class)->handle($this->staff, $withdrawal, 'REF');
            $this->fail('A pending withdrawal was paid.');
        } catch (ValidationException) {
        }

        app(ApproveWithdrawal::class)->handle($this->staff, $withdrawal);
        app(PayWithdrawal::class)->handle($this->staff, $withdrawal, 'REF');

        $this->expectException(ValidationException::class);
        app(PayWithdrawal::class)->handle($this->staff, $withdrawal, 'REF');
    }

    public function test_rejecting_releases_the_hold_and_keeps_the_reason(): void
    {
        $withdrawal = app(ApproveWithdrawal::class)->handle($this->staff, $this->request(200_000));

        app(RejectWithdrawal::class)->handle($this->staff, $withdrawal, 'The account name does not match.');

        $this->assertSame(WithdrawalStatus::Rejected, $withdrawal->status);
        $this->assertSame('The account name does not match.', $withdrawal->rejection_reason);
        $this->assertSame(1_000_000, $this->wallet->fresh()?->available()->amount);
        $this->assertLedgerBalanced();
    }

    public function test_only_staff_review_and_never_their_own_request(): void
    {
        $withdrawal = $this->request(200_000);

        foreach ([$this->buyer, User::factory()->companyOwner()->create()] as $user) {
            try {
                app(ApproveWithdrawal::class)->handle($user, $withdrawal);
                $this->fail("{$user->role->value} approved a withdrawal.");
            } catch (AuthorizationException) {
            }
        }

        $this->expectException(ValidationException::class);
        app(RejectWithdrawal::class)->handle($this->staff, $withdrawal, '  ');
    }

    public function test_the_verifier_notices_a_withdrawal_without_its_hold(): void
    {
        $withdrawal = $this->request(200_000);
        DB::table('withdrawal_requests')->where('id', $withdrawal->id)->update(['status' => WithdrawalStatus::Rejected->value]);

        $this->assertNotEmpty(array_filter(
            app(LedgerVerifier::class)->problems(),
            fn (string $problem) => str_starts_with($problem, "Withdrawal {$withdrawal->id} "),
        ));
    }

    /** @param  array<string, string>|null  $destination */
    private function request(int $amount, PayoutMethod $method = PayoutMethod::Bkash, ?array $destination = null): WithdrawalRequest
    {
        return app(RequestWithdrawal::class)->handle($this->buyer, $this->wallet, Money::minor($amount), $method, $destination ?? $this->mobile());
    }

    /** @return array<string, string> */
    private function mobile(): array
    {
        return ['account_name' => 'Ayesha Rahman', 'account_number' => '01712345678'];
    }
}
