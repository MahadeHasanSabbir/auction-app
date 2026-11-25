<?php

namespace Tests\Feature\Panels;

use App\Actions\Billing\SubscribeToPlan;
use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\WithdrawalStatus;
use App\Filament\Seller\Pages\Billing;
use App\Filament\Seller\Pages\CompanyProfile;
use App\Filament\Seller\Pages\CompanyWallet;
use App\Filament\Seller\Pages\Team;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\InteractsWithCompanies;
use Tests\Support\InteractsWithLedger;
use Tests\Support\InteractsWithOtp;
use Tests\TestCase;

/** The seller panel pages a company works in, as its owner and as a member. */
class SellerPagesTest extends TestCase
{
    use InteractsWithCompanies;
    use InteractsWithLedger;
    use InteractsWithOtp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPlatformAccounts();
    }

    private function openPanel(User $user, Company $company): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel('seller');
        Filament::setTenant($company);
    }

    public function test_a_new_company_is_sent_to_the_billing_page_until_it_has_a_plan(): void
    {
        [$company, $owner] = $this->company();

        $this->actingAs($owner)
            ->get("/seller/{$company->slug}")
            ->assertRedirect(Billing::getUrl(panel: 'seller', tenant: $company));

        $this->get("/seller/{$company->slug}/billing")->assertOk()->assertSee('Pick the plan that fits your team');
        $this->get("/seller/{$company->slug}/team")->assertRedirect(Billing::getUrl(panel: 'seller', tenant: $company));
    }

    public function test_a_company_with_a_plan_opens_every_page(): void
    {
        [$company, $owner] = $this->company($this->plan());

        $this->actingAs($owner);

        foreach (['', '/billing', '/team', '/profile', '/wallet'] as $path) {
            $this->get("/seller/{$company->slug}{$path}")->assertOk();
        }
    }

    public function test_a_company_whose_plan_ended_can_still_open_its_panel(): void
    {
        [$company, $owner] = $this->company($this->plan(), SubscriptionStatus::Expired);

        $this->actingAs($owner)->get("/seller/{$company->slug}")->assertOk()->assertSee('Your plan has ended');
    }

    public function test_the_dashboard_shows_the_company_at_a_glance(): void
    {
        $plan = $this->plan();
        [$company, $owner, $wallet] = $this->company($plan);
        $this->fund($wallet, 123_400);

        $this->actingAs($owner)
            ->get("/seller/{$company->slug}")
            ->assertOk()
            ->assertSee('Available balance')
            ->assertSee($plan->name);
    }

    public function test_the_owner_chooses_a_plan_and_pays_the_first_invoice_from_the_wallet(): void
    {
        $plan = $this->plan();
        [$company, $owner, $wallet] = $this->company();
        $this->fund($wallet, 300_000);
        $this->openPanel($owner, $company);

        Livewire::test(Billing::class)
            ->assertSee($plan->name)
            ->callAction(TestAction::make('selectPlan')->arguments(['plan' => $plan->id]))
            ->assertSee('Pay from company wallet')
            ->callAction(TestAction::make('payFromWallet')->arguments(['invoice' => SubscriptionInvoice::query()->sole()->id]))
            ->assertSee('Current plan');

        $this->assertSame(SubscriptionStatus::Active, $company->subscription()->first()?->status);
        $this->assertSame(200_000, $wallet->fresh()?->balance->amount);
    }

    public function test_the_owner_cancels_and_keeps_the_plan(): void
    {
        [$company, $owner] = $this->company($this->plan());
        $this->openPanel($owner, $company);

        Livewire::test(Billing::class)
            ->callAction('cancelPlan')
            ->assertActionVisible('resumePlan')
            ->callAction('resumePlan')
            ->assertActionVisible('cancelPlan');

        $this->assertNull($company->subscription()->first()?->cancelled_at);
    }

    public function test_a_member_sees_billing_but_cannot_change_it(): void
    {
        $plan = $this->plan();
        [$company] = $this->company($plan);
        $member = User::factory()->companyMember($company)->create();
        $this->openPanel($member, $company);

        Livewire::test(Billing::class)
            ->assertSee($plan->name)
            ->assertActionHidden('cancelPlan')
            ->assertActionHidden(TestAction::make('selectPlan')->arguments(['plan' => $plan->id]));
    }

    public function test_one_company_cannot_pay_anothers_invoice(): void
    {
        $plan = $this->plan();
        [$other, $otherOwner] = $this->company();
        ['invoice' => $invoice] = app(SubscribeToPlan::class)->handle($otherOwner, $other, $plan, BillingInterval::Monthly);
        [$company, $owner, $wallet] = $this->company($plan);
        $this->fund($wallet, 500_000);
        $this->openPanel($owner, $company);

        Livewire::test(Billing::class)
            ->call('mountAction', 'payFromWallet', ['invoice' => $invoice?->id])
            ->assertNotFound();

        $this->assertSame(InvoiceStatus::Open, $invoice?->fresh()?->status);
        $this->assertSame(500_000, $wallet->fresh()?->balance->amount);
    }

    public function test_the_team_page_invites_and_withdraws(): void
    {
        Notification::fake();
        [$company, $owner] = $this->company($this->plan());
        $this->openPanel($owner, $company);

        Livewire::test(Team::class)
            ->callAction('invite', ['email' => 'new@example.com'])
            ->assertHasNoFormErrors()
            ->assertSee('new@example.com');

        $invitation = CompanyInvitation::query()->where('email', 'new@example.com')->firstOrFail();

        Livewire::test(Team::class)
            ->callAction(TestAction::make('revoke')->arguments(['invitation' => $invitation->id]))
            ->assertDontSee('new@example.com');

        $this->assertNotNull($invitation->fresh()?->revoked_at);
    }

    public function test_the_team_page_removes_a_member(): void
    {
        [$company, $owner] = $this->company($this->plan());
        $member = User::factory()->companyMember($company)->create();
        $this->openPanel($owner, $company);

        Livewire::test(Team::class)
            ->assertCanSeeTableRecords([$owner, $member])
            ->assertActionHidden(TestAction::make('remove')->table($owner))
            ->callAction(TestAction::make('remove')->table($member));

        $this->assertSoftDeleted($member);
    }

    public function test_inviting_is_disabled_when_every_seat_is_taken(): void
    {
        [$company, $owner] = $this->company($this->plan(['max_users' => 1]));
        $this->openPanel($owner, $company);

        Livewire::test(Team::class)->assertActionDisabled('invite');
    }

    public function test_a_member_cannot_invite_or_remove(): void
    {
        [$company] = $this->company($this->plan());
        $member = User::factory()->companyMember($company)->create();
        $this->openPanel($member, $company);

        Livewire::test(Team::class)
            ->assertActionHidden('invite')
            ->assertActionHidden(TestAction::make('remove')->table($member));
    }

    public function test_the_profile_page_saves_for_the_owner(): void
    {
        [$company, $owner] = $this->company($this->plan());
        $this->openPanel($owner, $company);

        Livewire::test(CompanyProfile::class)
            ->assertSchemaStateSet(['name' => $company->name])
            ->fillForm(['name' => 'Renamed Ltd', 'about' => 'New text'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Renamed Ltd', $company->fresh()?->name);
        $this->assertSame($company->slug, $company->fresh()?->slug, 'The address in the panel does not change.');
    }

    public function test_the_profile_page_shows_errors(): void
    {
        [$company, $owner] = $this->company($this->plan());
        $this->openPanel($owner, $company);

        Livewire::test(CompanyProfile::class)
            ->fillForm(['name' => ''])
            ->call('save')
            ->assertHasFormErrors(['name']);
    }

    public function test_a_payout_needs_the_emailed_code_first_and_can_be_cancelled(): void
    {
        [$company, $owner, $wallet] = $this->company($this->plan());
        $this->fund($wallet, 500_000);
        $this->openPanel($owner, $company);

        $form = ['amount' => '1000', 'method' => 'bkash', 'account_name' => 'Owner', 'account_number' => '01712345678'];

        Livewire::test(CompanyWallet::class)
            ->callAction('requestPayout', $form)
            ->assertRedirect(route('otp.confirm'));

        $this->assertSame(0, WithdrawalRequest::query()->count());

        $this->withConfirmedOtp();

        Livewire::test(CompanyWallet::class)
            ->callAction('requestPayout', $form)
            ->assertHasNoFormErrors();

        $withdrawal = WithdrawalRequest::query()->sole();
        $this->assertSame(WithdrawalStatus::Pending, $withdrawal->status);
        $this->assertSame($wallet->id, $withdrawal->wallet_id);
        $this->assertSame(100_000, $wallet->fresh()?->held->amount);
        $this->assertLedgerBalanced();

        Livewire::test(CompanyWallet::class)
            ->callAction(TestAction::make('cancelPayout')->arguments(['withdrawal' => $withdrawal->id]));

        $this->assertSame(WithdrawalStatus::Cancelled, $withdrawal->fresh()?->status);
        $this->assertSame(0, $wallet->fresh()?->held->amount);
    }

    public function test_a_member_cannot_request_a_payout(): void
    {
        [$company, , $wallet] = $this->company($this->plan());
        $this->fund($wallet, 500_000);
        $member = User::factory()->companyMember($company)->create();
        $this->openPanel($member, $company);
        $this->withConfirmedOtp();

        Livewire::test(CompanyWallet::class)->assertActionHidden('requestPayout');

        $this->assertSame(0, WithdrawalRequest::query()->count());
    }
}
