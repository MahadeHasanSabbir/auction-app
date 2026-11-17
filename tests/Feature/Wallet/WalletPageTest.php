<?php

namespace Tests\Feature\Wallet;

use App\Actions\Wallet\RequestWithdrawal;
use App\Enums\DepositStatus;
use App\Enums\PayoutMethod;
use App\Enums\WithdrawalStatus;
use App\Livewire\Wallet\Show;
use App\Livewire\Wallet\Withdraw;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\InteractsWithLedger;
use Tests\Support\InteractsWithOtp;
use Tests\TestCase;

class WalletPageTest extends TestCase
{
    use InteractsWithLedger;
    use InteractsWithOtp;
    use RefreshDatabase;

    private User $buyer;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPlatformAccounts();
        $this->buyer = User::factory()->create();
        $this->wallet = $this->fund(Wallet::factory()->create(['user_id' => $this->buyer->id]), 1_000_000);
    }

    public function test_a_buyer_sees_balances_and_the_statement(): void
    {
        $this->actingAs($this->buyer)->get(route('wallet'))
            ->assertOk()
            ->assertSee('Statement')
            ->assertSee('Adjustment')
            ->assertSee(Money::minor(1_000_000)->format());
    }

    public function test_guests_and_sellers_do_not_get_the_buyer_wallet(): void
    {
        $this->get(route('wallet'))->assertRedirect(route('login'));

        Livewire::actingAs(User::factory()->companyOwner()->create())
            ->test(Show::class)
            ->assertRedirect();
    }

    public function test_adding_money_sends_the_buyer_to_the_gateway_and_back(): void
    {
        $component = Livewire::actingAs($this->buyer)
            ->test(Show::class)
            ->set('amount', '500')
            ->assertSee(Money::minor(50_000)->format())
            ->call('startDeposit');

        $deposit = Deposit::query()->sole();
        $this->assertSame(50_000, $deposit->amount->amount);
        $component->assertRedirectContains('/payments/fake/return?');

        // The fake gateway pays at once; following the redirect credits it.
        $target = $component->effects['redirect'];
        $this->get($target)->assertRedirect(route('wallet', ['deposit' => $deposit->tran_id]));

        $this->assertSame(DepositStatus::Succeeded, $deposit->fresh()?->status);
        $this->actingAs($this->buyer)->get(route('wallet', ['deposit' => $deposit->tran_id]))
            ->assertSee(__(':amount was added to your wallet.', ['amount' => Money::minor(50_000)->format()]));
    }

    public function test_bad_amounts_are_refused_on_the_form(): void
    {
        Livewire::actingAs($this->buyer)
            ->test(Show::class)
            ->set('amount', '12,5')
            ->call('startDeposit')
            ->assertHasErrors('amount')
            ->set('amount', '1')
            ->call('startDeposit')
            ->assertHasErrors('amount');

        $this->assertSame(0, Deposit::query()->count());
    }

    public function test_someone_elses_payment_reference_shows_nothing(): void
    {
        $other = User::factory()->create();
        $otherWallet = Wallet::factory()->create(['user_id' => $other->id]);
        $deposit = (new Deposit)->forceFill([
            'wallet_id' => $otherWallet->id, 'user_id' => $other->id, 'gateway' => 'fake', 'tran_id' => 'DEPOTHER',
            'amount' => 50_000, 'net_amount' => 50_000, 'currency' => 'BDT', 'status' => DepositStatus::Succeeded,
        ]);
        $deposit->save();

        $this->actingAs($this->buyer)->get(route('wallet', ['deposit' => $deposit->tran_id]))
            ->assertOk()
            ->assertDontSee('was added to your wallet');
    }

    public function test_the_withdraw_page_asks_for_a_code_first(): void
    {
        $this->actingAs($this->buyer)->get(route('wallet.withdraw'))->assertRedirect(route('otp.confirm'));

        $this->actingAs($this->buyer)->withConfirmedOtp()->get(route('wallet.withdraw'))->assertOk()->assertSee('Request withdrawal');
    }

    public function test_a_buyer_requests_a_withdrawal(): void
    {
        $this->withConfirmedOtp();

        Livewire::actingAs($this->buyer)
            ->test(Withdraw::class)
            ->set('amount', '2000')
            ->set('method', PayoutMethod::Nagad->value)
            ->set('account_number', '01812-345678')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('wallet'));

        $withdrawal = WithdrawalRequest::query()->sole();
        $this->assertSame(PayoutMethod::Nagad, $withdrawal->method);
        $this->assertSame('01812345678', $withdrawal->destination['account_number']);
        $this->assertSame(200_000, $this->wallet->fresh()?->held->amount);
    }

    public function test_the_submit_checks_the_code_again(): void
    {
        Livewire::actingAs($this->buyer)
            ->test(Withdraw::class)
            ->set('amount', '2000')
            ->set('account_number', '01812345678')
            ->call('submit')
            ->assertRedirect(route('otp.confirm'));

        $this->assertSame(0, WithdrawalRequest::query()->count());
    }

    public function test_a_pending_withdrawal_can_be_cancelled_from_the_wallet_but_not_someone_elses(): void
    {
        $withdrawal = app(RequestWithdrawal::class)->handle($this->buyer, $this->wallet, Money::minor(100_000), PayoutMethod::Bkash, [
            'account_name' => 'A', 'account_number' => '01712345678',
        ]);

        Livewire::actingAs($this->buyer)
            ->test(Show::class)
            ->assertSee('bKash ••5678')
            ->call('cancelWithdrawal', $withdrawal->id)
            ->assertDispatched('toast');

        $this->assertSame(WithdrawalStatus::Cancelled, $withdrawal->fresh()?->status);

        $stranger = User::factory()->create();
        Wallet::factory()->create(['user_id' => $stranger->id]);

        Livewire::actingAs($stranger)
            ->test(Show::class)
            ->call('cancelWithdrawal', $withdrawal->id)
            ->assertNotFound();
    }
}
