<?php

namespace Tests\Feature\Invoices;

use App\Actions\Bidding\BuyNow;
use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\PayInvoice;
use App\Actions\Orders\CompleteOrder;
use App\Actions\Orders\ShipOrder;
use App\Actions\Wallet\ApproveWithdrawal;
use App\Actions\Wallet\CompleteDeposit;
use App\Actions\Wallet\PayWithdrawal;
use App\Actions\Wallet\RequestWithdrawal;
use App\Actions\Wallet\StartDeposit;
use App\Enums\BillingInterval;
use App\Enums\CommissionBasis;
use App\Enums\FeeChargeType;
use App\Enums\FeeUseCase;
use App\Enums\InvoiceKind;
use App\Enums\LedgerComponent;
use App\Enums\PayoutMethod;
use App\Enums\SystemAccount;
use App\Filament\Seller\Resources\Invoices\Pages\ListInvoices;
use App\Livewire\Account\Invoices;
use App\Models\Auction;
use App\Models\Company;
use App\Models\FeeRule;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Fees\CommissionTerms;
use App\Services\Payments\CheckoutUrls;
use App\Services\Payments\PaymentGateways;
use App\Support\Money;
use App\Support\Vat;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Tests\Support\InteractsWithCompanies;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

/** VAT on the platform's fees, the numbered documents and who may download them. */
class InvoiceTest extends TestCase
{
    use InteractsWithCompanies;
    use InteractsWithLedger;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfSecond());
        $this->createPlatformAccounts();
        config(['auction.tax.vat_bps' => 1_500]);
    }

    private function buyer(int $minor = 0): User
    {
        $buyer = User::factory()->create(['address' => 'House 1, Dhaka']);
        $wallet = Wallet::factory()->create(['user_id' => $buyer->id]);

        if ($minor > 0) {
            $this->fund($wallet, $minor);
        }

        return $buyer;
    }

    /** A sale of 2,000.00 at 10% commission, delivered and confirmed. */
    private function completedOrder(): Order
    {
        [$company, $owner] = $this->company();
        $buyer = $this->buyer(1_000_000);
        $auction = Auction::factory()->live()->create([
            'company_id' => $company->id,
            'starting_price' => 100_000,
            'buy_now_price' => 200_000,
            'ends_at' => now()->addDay(),
            'fee_snapshot' => ['sale_commission' => (new CommissionTerms(1_000, Money::zero(), null, null, CommissionBasis::FinalPrice))->toSnapshot()],
        ]);

        $order = app(BuyNow::class)->handle($buyer, $auction);
        app(ShipOrder::class)->handle($owner, $order, ['courier' => 'Sundarban', 'tracking_number' => 'SB-1']);

        return app(CompleteOrder::class)->confirm($buyer, $order);
    }

    public function test_vat_is_the_part_of_a_gross_amount_and_rounds_half_up(): void
    {
        $this->assertSame(15, Vat::included(Money::minor(115))->amount);
        $this->assertSame(2_609, Vat::included(Money::minor(20_000))->amount);
        $this->assertSame(0, Vat::included(Money::minor(20_000), 0)->amount);
        $this->assertSame(0, Vat::included(Money::zero())->amount);

        [$net, $tax] = Vat::split(Money::minor(1_000));
        $this->assertSame(1_000, $net->plus($tax)->amount);
        $this->assertSame(130, $tax->amount);
    }

    public function test_a_deposit_moves_the_vat_in_its_charges_and_gets_a_receipt(): void
    {
        FeeRule::factory()->create(['charge_type' => FeeChargeType::Gateway, 'gateway' => 'fake', 'percent_bps' => 250]);
        FeeRule::factory()->create(['charge_type' => FeeChargeType::Service, 'percent_bps' => 100]);
        $buyer = $this->buyer();

        $checkout = app(StartDeposit::class)->handle($buyer, Money::minor(100_000), 'fake', new CheckoutUrls('https://shop.test/r', 'https://shop.test/f', 'https://shop.test/c'))['checkout'];
        parse_str((string) parse_url($checkout->redirectUrl, PHP_URL_QUERY), $params);
        /** @var array<string, string> $params */
        $deposit = app(CompleteDeposit::class)->handle(app(PaymentGateways::class)->get('fake')->verify(Request::create('/r', 'GET', $params)));

        // 25.00 gateway charge holds 3.26 VAT, 10.00 service charge 1.30.
        $this->assertSame(2_174, $this->system(SystemAccount::GatewayCharges)->balance->amount);
        $this->assertSame(870, $this->system(SystemAccount::ServiceRevenue)->balance->amount);
        $this->assertSame(456, $this->system(SystemAccount::TaxPayable)->balance->amount);
        $this->assertSame(96_500, Wallet::query()->where('user_id', $buyer->id)->firstOrFail()->balance->amount);
        $this->assertSame(2, $deposit->ledgerTransaction()->sole()->entries()->where('component', LedgerComponent::Tax)->count());

        $receipt = Invoice::query()->sole();
        $this->assertSame(InvoiceKind::Deposit, $receipt->kind);
        $this->assertSame($buyer->id, $receipt->user_id);
        $this->assertSame(96_500, $receipt->total->amount);
        $this->assertSame(3_500, $receipt->taxable->amount);
        $this->assertSame(456, $receipt->tax->amount);
        $this->assertSame(1_500, $receipt->tax_rate_bps);
        $this->assertSame([100_000, -2_500, -1_000], array_column($receipt->lines, 'amount'));

        // The same callback again issues nothing new.
        app(CompleteDeposit::class)->handle(app(PaymentGateways::class)->get('fake')->verify(Request::create('/r', 'GET', $params)));
        $this->assertSame(1, Invoice::query()->count());
        $this->assertLedgerBalanced();
    }

    public function test_a_settled_order_gives_a_receipt_and_a_commission_invoice(): void
    {
        $order = $this->completedOrder();

        // 200.00 commission of which 26.09 is VAT.
        $this->assertSame(20_000, $order->commission->amount);
        $this->assertSame(2_609, $order->tax->amount);
        $this->assertSame(17_391, $this->system(SystemAccount::CommissionRevenue)->balance->amount);
        $this->assertSame(2_609, $this->system(SystemAccount::TaxPayable)->balance->amount);

        $receipt = Invoice::query()->where('kind', InvoiceKind::Purchase)->sole();
        $this->assertSame($order->buyer_id, $receipt->user_id);
        $this->assertSame(200_000, $receipt->total->amount);
        $this->assertSame(0, $receipt->tax->amount);
        $this->assertStringStartsWith('PUR-', $receipt->number);

        $commission = Invoice::query()->where('kind', InvoiceKind::Commission)->sole();
        $this->assertSame($order->company_id, $commission->company_id);
        $this->assertNull($commission->user_id);
        $this->assertSame(180_000, $commission->total->amount);
        $this->assertSame(20_000, $commission->taxable->amount);
        $this->assertSame(2_609, $commission->tax->amount);
        $this->assertLedgerBalanced();
    }

    public function test_a_paid_plan_invoice_and_a_paid_withdrawal_get_documents(): void
    {
        $plan = $this->plan(['price_monthly' => 230_000]);
        [$company, $owner, $companyWallet] = $this->company($plan);
        $this->fund($companyWallet, 1_000_000);
        $subscription = $company->subscription()->firstOrFail();
        $planInvoice = app(IssueInvoice::class)->handle($subscription, $plan, BillingInterval::Monthly, Money::minor(230_000), now(), now()->addMonth());
        app(PayInvoice::class)->fromWallet($planInvoice);

        $document = Invoice::query()->where('kind', InvoiceKind::Subscription)->sole();
        $this->assertSame($company->id, $document->company_id);
        $this->assertSame($planInvoice->amount->amount, $document->total->amount);
        $this->assertSame(30_000, $document->tax->amount);
        $this->assertSame(200_000, $this->system(SystemAccount::SubscriptionRevenue)->balance->amount);

        FeeRule::factory()->create(['use_case' => FeeUseCase::Withdrawal, 'charge_type' => FeeChargeType::Service, 'percent_bps' => 100]);
        config(['auction.payments.withdrawal_min' => 50_000]);
        $staff = User::factory()->platformStaff()->create();
        $withdrawal = app(RequestWithdrawal::class)->handle($owner, $companyWallet, Money::minor(100_000), PayoutMethod::Bkash, ['account_name' => 'Owner', 'account_number' => '01712345678']);
        app(ApproveWithdrawal::class)->handle($staff, $withdrawal);
        app(PayWithdrawal::class)->handle($staff, $withdrawal->refresh(), 'BK-1');

        $receipt = Invoice::query()->where('kind', InvoiceKind::Withdrawal)->sole();
        $this->assertSame($company->id, $receipt->company_id);
        $this->assertSame(99_000, $receipt->total->amount);
        $this->assertSame(130, $receipt->tax->amount);
        $this->assertLedgerBalanced();
    }

    public function test_numbers_run_per_kind_and_year_without_gaps(): void
    {
        $this->completedOrder();
        $this->completedOrder();

        $year = now()->setTimezone((string) config('auction.display_timezone'))->year;
        $this->assertSame(
            ["PUR-{$year}-000001", "PUR-{$year}-000002"],
            Invoice::query()->where('kind', InvoiceKind::Purchase)->orderBy('id')->pluck('number')->all(),
        );
        $this->assertSame("COM-{$year}-000002", Invoice::query()->where('kind', InvoiceKind::Commission)->latest('id')->value('number'));
    }

    public function test_only_the_owner_the_company_team_and_staff_download_a_document(): void
    {
        $order = $this->completedOrder();
        $receipt = Invoice::query()->where('kind', InvoiceKind::Purchase)->sole();
        $commission = Invoice::query()->where('kind', InvoiceKind::Commission)->sole();
        $buyer = User::query()->findOrFail($order->buyer_id);
        $member = User::factory()->companyMember($order->company_id)->create();

        $this->actingAs($buyer)->get(route('invoices.pdf', $receipt))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->actingAs($buyer)->get(route('invoices.pdf', $commission))->assertForbidden();
        $this->actingAs($this->buyer())->get(route('invoices.pdf', $receipt))->assertForbidden();
        $this->actingAs($member)->get(route('invoices.pdf', $commission))->assertOk();
        $this->actingAs($member)->get(route('invoices.pdf', $receipt))->assertForbidden();
        $this->actingAs(User::factory()->platformStaff()->create())->get(route('invoices.pdf', $commission))->assertOk();

        Livewire::actingAs($buyer)->test(Invoices::class)
            ->assertSee($receipt->number)
            ->assertDontSee($commission->number);
    }

    public function test_the_seller_panel_lists_the_company_invoices_only(): void
    {
        $order = $this->completedOrder();
        $this->completedOrder();
        $mine = Invoice::query()->where('kind', InvoiceKind::Commission)->where('company_id', $order->company_id)->sole();
        $theirs = Invoice::query()->where('kind', InvoiceKind::Commission)->where('company_id', '!=', $order->company_id)->sole();
        $owner = User::query()->where('company_id', $order->company_id)->firstOrFail();

        $this->actingAs($owner);
        Filament::setCurrentPanel('seller');
        Filament::setTenant(Company::query()->findOrFail($order->company_id));

        Livewire::test(ListInvoices::class)
            ->assertSee($mine->number)
            ->assertDontSee($theirs->number);
    }
}
