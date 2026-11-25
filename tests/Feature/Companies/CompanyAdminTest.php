<?php

namespace Tests\Feature\Companies;

use App\Actions\Companies\AssignPlan;
use App\Actions\Companies\ReinstateCompany;
use App\Actions\Companies\SetAutoApprove;
use App\Actions\Companies\SuspendCompany;
use App\Actions\Companies\UpdateCompanyProfile;
use App\Actions\Companies\VerifyCompany;
use App\Enums\BillingInterval;
use App\Enums\CompanyStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\CompanyAgreement;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\InteractsWithCompanies;
use Tests\TestCase;

class CompanyAdminTest extends TestCase
{
    use InteractsWithCompanies;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->systemOwner()->create();
    }

    public function test_verifying_activates_a_pending_company_once(): void
    {
        $company = Company::factory()->pending()->create();

        app(VerifyCompany::class)->handle($this->owner, $company);

        $this->assertSame(CompanyStatus::Active, $company->fresh()?->status);
        $this->assertNotNull($company->fresh()?->verified_at);
        $this->assertSame(1, Activity::query()->where('log_name', 'admin')->where('event', 'verified')->count());

        $this->expectException(ValidationException::class);
        app(VerifyCompany::class)->handle($this->owner, $company);
    }

    public function test_suspending_needs_a_reason_and_reinstating_goes_back(): void
    {
        $company = Company::factory()->create();

        try {
            app(SuspendCompany::class)->handle($this->owner, $company, '  ');
            $this->fail('A suspension without a reason was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }

        app(SuspendCompany::class)->handle($this->owner, $company, 'Fake listings reported.');

        $company->refresh();
        $this->assertSame(CompanyStatus::Suspended, $company->status);
        $this->assertSame('Fake listings reported.', $company->suspension_reason);

        app(ReinstateCompany::class)->handle($this->owner, $company);

        $company->refresh();
        $this->assertSame(CompanyStatus::Active, $company->status);
        $this->assertNull($company->suspension_reason);
    }

    public function test_a_company_never_verified_goes_back_to_pending_when_reinstated(): void
    {
        $company = Company::factory()->pending()->create();
        app(SuspendCompany::class)->handle($this->owner, $company, 'Checking.');

        app(ReinstateCompany::class)->handle($this->owner, $company);

        $this->assertSame(CompanyStatus::Pending, $company->fresh()?->status);
    }

    public function test_auto_approve_is_switched_and_logged(): void
    {
        $company = Company::factory()->create();

        app(SetAutoApprove::class)->handle($this->owner, $company, true);
        $this->assertTrue($company->fresh()?->auto_approve);

        app(SetAutoApprove::class)->handle($this->owner, $company, false);
        $this->assertFalse($company->fresh()?->auto_approve);
        $this->assertSame(2, Activity::query()->where('log_name', 'admin')->count());
    }

    public function test_assigning_a_plan_sets_agreed_terms_and_voids_open_invoices(): void
    {
        $plan = $this->plan(['is_public' => false]);
        [$company] = $this->company($plan, SubscriptionStatus::PastDue);
        $subscription = $company->subscription()->firstOrFail();
        $invoice = SubscriptionInvoice::query()->forceCreate([
            'subscription_id' => $subscription->id, 'company_id' => $company->id, 'plan_id' => $plan->id,
            'amount' => 100_000, 'currency' => 'BDT', 'status' => InvoiceStatus::Open,
            'period_start' => now()->subMonth(), 'period_end' => now(), 'due_at' => now()->addDay(),
        ]);

        app(AssignPlan::class)->handle($this->owner, $company, $plan, BillingInterval::Yearly, Money::minor(750_000), 25, 40);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame(750_000, $subscription->price->amount);
        $this->assertSame(25, $subscription->maxUsers());
        $this->assertSame(40, $subscription->maxActiveAuctions());
        $this->assertNull($subscription->grace_ends_at);
        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()?->status);
        $this->assertSame(1, Activity::query()->where('event', 'plan_assigned')->count());
    }

    public function test_assigning_a_plan_refuses_limits_below_the_team(): void
    {
        $plan = $this->plan();
        [$company] = $this->company($plan);
        User::factory()->companyMember($company)->count(2)->create();

        $this->expectException(ValidationException::class);

        app(AssignPlan::class)->handle($this->owner, $company, $plan, BillingInterval::Monthly, null, 2);
    }

    public function test_assigning_a_plan_without_a_price_for_the_interval_is_refused(): void
    {
        $plan = $this->plan(['price_yearly' => null]);
        [$company] = $this->company();

        $this->expectException(ValidationException::class);

        app(AssignPlan::class)->handle($this->owner, $company, $plan, BillingInterval::Yearly);
    }

    public function test_only_the_system_owner_decides_about_companies(): void
    {
        $company = Company::factory()->pending()->create();
        $staff = User::factory()->platformStaff()->create();
        $companyOwner = User::factory()->companyOwner($company)->create();

        foreach ([$staff, $companyOwner, User::factory()->create()] as $user) {
            try {
                app(VerifyCompany::class)->handle($user, $company);
                $this->fail('Someone but the system owner verified a company.');
            } catch (AuthorizationException) {
            }
        }

        $this->assertSame(CompanyStatus::Pending, $company->fresh()?->status);
        $this->assertFalse($staff->can('viewAny', Company::class));
        $this->assertFalse($staff->can('viewAny', Plan::class));
        $this->assertFalse($staff->can('viewAny', CompanyAgreement::class));
        $this->assertTrue($this->owner->can('viewAny', Company::class));
        $this->assertTrue($this->owner->can('create', Plan::class));
    }

    public function test_the_owner_edits_the_company_profile_and_the_logo_is_re_encoded(): void
    {
        Storage::fake('public');
        [$company, $owner] = $this->company();

        app(UpdateCompanyProfile::class)->handle($owner, $company, [
            'name' => 'Dhaka Antiques Ltd', 'legal_name' => '', 'email' => 'hello@example.com', 'phone' => '01712345678', 'address' => '', 'about' => 'We sell old things.',
        ], UploadedFile::fake()->image('logo.png', 300, 200));

        $company->refresh();
        $this->assertSame('Dhaka Antiques Ltd', $company->name);
        $this->assertNull($company->legal_name, 'An empty field is stored as null.');
        $this->assertStringEndsWith('.webp', (string) $company->logo_path);
        Storage::disk('public')->assertExists((string) $company->logo_path);

        $old = (string) $company->logo_path;
        app(UpdateCompanyProfile::class)->handle($owner, $company, ['name' => 'Dhaka Antiques Ltd'], null, removeLogo: true);

        $this->assertNull($company->fresh()?->logo_path);
        Storage::disk('public')->assertMissing($old);
    }

    public function test_members_cannot_edit_the_profile_and_the_name_is_required(): void
    {
        [$company, $owner] = $this->company();
        $member = User::factory()->companyMember($company)->create();

        try {
            app(UpdateCompanyProfile::class)->handle($member, $company, ['name' => 'Hijacked']);
            $this->fail('A member edited the profile.');
        } catch (AuthorizationException) {
        }

        $this->expectException(ValidationException::class);
        app(UpdateCompanyProfile::class)->handle($owner, $company, ['name' => '']);
    }
}
