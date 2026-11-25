<?php

namespace Tests\Feature\Panels;

use App\Enums\CompanyStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Admin\Resources\Companies\Pages\ListCompanies;
use App\Filament\Admin\Resources\Companies\Pages\ViewCompany;
use App\Filament\Admin\Resources\Companies\RelationManagers\AgreementsRelationManager;
use App\Filament\Admin\Resources\Plans\Pages\ManagePlans;
use App\Models\Company;
use App\Models\CompanyAgreement;
use App\Models\Plan;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\InteractsWithCompanies;
use Tests\TestCase;

/** Plans, companies and company agreements in the admin panel: the system owner only. */
class AdminResourcesTest extends TestCase
{
    use InteractsWithCompanies;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->systemOwner()->create();
    }

    private function openAdmin(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
    }

    public function test_the_system_owner_opens_the_pages_and_staff_do_not(): void
    {
        [$company] = $this->company($this->plan());

        $this->actingAs($this->owner);
        $this->get('/admin/plans')->assertOk();
        $this->get('/admin/companies')->assertOk()->assertSee($company->name);
        $this->get("/admin/companies/{$company->id}")->assertOk();

        $this->actingAs(User::factory()->platformStaff()->create());
        $this->get('/admin/plans')->assertForbidden();
        $this->get('/admin/companies')->assertForbidden();
        $this->get("/admin/companies/{$company->id}")->assertForbidden();
    }

    public function test_a_plan_is_created_with_prices_in_whole_currency_units(): void
    {
        $this->openAdmin($this->owner);

        Livewire::test(ManagePlans::class)
            ->callAction('create', [
                'name' => 'Gold',
                'slug' => 'gold',
                'price_monthly' => '1500.50',
                'price_yearly' => '15000',
                'max_users' => 12,
                'max_active_auctions' => 30,
                'trial_days' => 7,
                'sort_order' => 4,
                'features' => ['Priority support'],
                'is_public' => true,
                'is_active' => true,
            ])
            ->assertHasNoActionErrors();

        $plan = Plan::query()->where('slug', 'gold')->firstOrFail();
        $this->assertSame(150_050, $plan->price_monthly->amount);
        $this->assertSame(1_500_000, $plan->price_yearly?->amount);
        $this->assertSame(['Priority support'], $plan->features);
    }

    public function test_a_plan_price_with_too_many_decimals_is_refused(): void
    {
        $this->openAdmin($this->owner);

        Livewire::test(ManagePlans::class)
            ->callAction('create', [
                'name' => 'Odd', 'slug' => 'odd', 'price_monthly' => '10.999', 'max_users' => 2,
                'trial_days' => 0, 'sort_order' => 0, 'is_public' => true, 'is_active' => true,
            ])
            ->assertHasActionErrors(['price_monthly']);
    }

    public function test_the_companies_list_verifies_and_suspends(): void
    {
        $pending = Company::factory()->pending()->create();
        $active = Company::factory()->create();
        $this->openAdmin($this->owner);

        Livewire::test(ListCompanies::class)
            ->assertCanSeeTableRecords([$pending, $active])
            ->callAction(TestAction::make('verify')->table($pending))
            ->callAction(TestAction::make('suspend')->table($active), ['reason' => 'Complaints from buyers.']);

        $this->assertSame(CompanyStatus::Active, $pending->fresh()?->status);
        $this->assertSame(CompanyStatus::Suspended, $active->fresh()?->status);
    }

    public function test_the_company_page_assigns_a_plan(): void
    {
        $plan = $this->plan();
        [$company] = $this->company();
        $this->openAdmin($this->owner);

        Livewire::test(ViewCompany::class, ['record' => $company->getRouteKey()])
            ->callAction('assignPlan', ['plan_id' => $plan->id, 'interval' => 'monthly', 'price' => '500', 'max_users' => 9])
            ->assertHasNoActionErrors();

        $subscription = $company->subscription()->firstOrFail();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame(50_000, $subscription->price->amount);
        $this->assertSame(9, $subscription->maxUsers());
    }

    public function test_agreements_are_added_with_a_percentage_and_stored_as_basis_points(): void
    {
        [$company] = $this->company();
        $this->openAdmin($this->owner);

        Livewire::test(AgreementsRelationManager::class, ['ownerRecord' => $company, 'pageClass' => ViewCompany::class])
            ->callAction(TestAction::make('create')->table(), [
                'basis' => 'final_price',
                'rate_bps' => '7.5',
                'fixed_amount' => '0',
                'effective_from' => now()->toDateTimeString(),
                'note' => 'Launch partner',
            ])
            ->assertHasNoActionErrors();

        $agreement = CompanyAgreement::query()->sole();
        $this->assertSame(750, $agreement->rate_bps);
        $this->assertSame($company->id, $agreement->company_id);
        $this->assertSame($this->owner->id, $agreement->created_by);
        $this->assertSame('sale_commission', $agreement->use_case->value);
    }
}
