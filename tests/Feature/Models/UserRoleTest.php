<?php

namespace Tests\Feature\Models;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_account_is_a_buyer(): void
    {
        $user = User::query()->create([
            'name' => 'New Person',
            'email' => 'new@example.com',
            'password' => 'password',
        ]);

        $this->assertSame(UserRole::Buyer, $user->refresh()->role);
        $this->assertNull($user->company_id);
        $this->assertTrue($user->is_active);
    }

    public function test_role_and_company_cannot_be_mass_assigned(): void
    {
        $company = Company::factory()->create();

        $user = User::query()->create([
            'name' => 'Sneaky Person',
            'email' => 'sneaky@example.com',
            'password' => 'password',
            'role' => UserRole::SystemOwner->value,
            'company_id' => $company->id,
            'is_active' => false,
        ]);

        $user->refresh();

        $this->assertSame(UserRole::Buyer, $user->role);
        $this->assertNull($user->company_id);
        $this->assertTrue($user->is_active);
    }

    public function test_a_company_role_needs_a_company(): void
    {
        $user = User::factory()->make();
        $user->role = UserRole::CompanyMember;

        $this->expectException(LogicException::class);

        $user->save();
    }

    public function test_a_buyer_cannot_belong_to_a_company(): void
    {
        $company = Company::factory()->create();

        $user = User::factory()->make();
        $user->company_id = $company->id;

        $this->expectException(LogicException::class);

        $user->save();
    }

    public function test_platform_roles_cannot_belong_to_a_company(): void
    {
        $company = Company::factory()->create();

        $user = User::factory()->platformStaff()->make();
        $user->company_id = $company->id;

        $this->expectException(LogicException::class);

        $user->save();
    }

    public function test_a_company_knows_its_owner_and_team(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->companyOwner($company)->create();
        User::factory()->companyMember($company)->count(2)->create();
        User::factory()->companyMember()->create();

        $company->load(['owner', 'users']);

        $this->assertTrue($company->owner->is($owner));
        $this->assertCount(3, $company->users);
    }
}
