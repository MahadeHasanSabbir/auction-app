<?php

namespace Tests\Feature\Panels;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who may open which panel. /admin is for platform roles; /seller is for
 * company roles and only ever shows their own company.
 */
class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_one_site_login(): void
    {
        $company = Company::factory()->create();

        $this->get('/admin')->assertRedirect('/login');
        $this->get("/seller/{$company->slug}")->assertRedirect('/login');

        // The panels have no sign-in page of their own. ("login" under
        // /seller is read as a company address, and guests are sent away.)
        $this->get('/admin/login')->assertNotFound();
        $this->get('/seller/login')->assertRedirect('/login');
    }

    public function test_platform_roles_can_open_the_admin_panel(): void
    {
        $this->actingAs(User::factory()->systemOwner()->create())->get('/admin')->assertOk();
        $this->actingAs(User::factory()->platformStaff()->create())->get('/admin')->assertOk();
    }

    public function test_buyers_and_sellers_cannot_open_the_admin_panel(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
        $this->actingAs(User::factory()->companyOwner()->create())->get('/admin')->assertForbidden();
        $this->actingAs(User::factory()->companyMember()->create())->get('/admin')->assertForbidden();
    }

    public function test_a_deactivated_staff_account_cannot_open_the_admin_panel(): void
    {
        $this->actingAs(User::factory()->platformStaff()->inactive()->create())->get('/admin')->assertForbidden();
    }

    public function test_company_roles_open_the_seller_panel_of_their_own_company(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->companyOwner($company)->create();
        $member = User::factory()->companyMember($company)->create();
        Subscription::factory()->create(['company_id' => $company->id]);

        $this->actingAs($owner)->get('/seller')->assertRedirect("/seller/{$company->slug}");
        $this->actingAs($owner)->get("/seller/{$company->slug}")->assertOk();
        $this->actingAs($member)->get("/seller/{$company->slug}")->assertOk();
    }

    public function test_a_company_without_a_plan_is_sent_to_choose_one(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->companyOwner($company)->create();

        $this->actingAs($owner)->get("/seller/{$company->slug}")->assertRedirect("/seller/{$company->slug}/billing");
    }

    public function test_a_member_cannot_open_another_company(): void
    {
        $member = User::factory()->companyMember()->create();
        $other = Company::factory()->create();

        $this->actingAs($member)->get("/seller/{$other->slug}")->assertNotFound();
    }

    public function test_buyers_and_platform_roles_cannot_open_the_seller_panel(): void
    {
        $company = Company::factory()->create();

        $this->actingAs(User::factory()->create())->get("/seller/{$company->slug}")->assertForbidden();
        $this->actingAs(User::factory()->platformStaff()->create())->get("/seller/{$company->slug}")->assertForbidden();
        $this->actingAs(User::factory()->systemOwner()->create())->get("/seller/{$company->slug}")->assertForbidden();
    }
}
