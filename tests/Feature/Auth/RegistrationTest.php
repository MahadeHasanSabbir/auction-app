<?php

namespace Tests\Feature\Auth;

use App\Enums\CompanyStatus;
use App\Enums\OtpPurpose;
use App\Enums\UserRole;
use App\Enums\WalletType;
use App\Models\Company;
use App\Models\User;
use App\Models\UserType;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\InteractsWithOtp;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use InteractsWithOtp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_the_sign_up_page_offers_both_account_types(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('I want to bid')
            ->assertSee('I want to sell');

        $this->get('/register?type=seller')->assertOk();
    }

    public function test_a_buyer_can_sign_up(): void
    {
        $type = UserType::factory()->create(['is_default' => true]);

        $response = $this->post('/register', [
            'account_type' => 'buyer',
            'name' => 'Test Buyer',
            'email' => 'buyer@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::query()->where('email', 'buyer@example.com')->sole();

        $response->assertRedirect(route('verification.notice'));
        $this->assertAuthenticatedAs($user);

        $this->assertSame(UserRole::Buyer, $user->role);
        $this->assertNull($user->company_id);
        $this->assertSame($type->id, $user->user_type_id);
        $this->assertFalse($user->hasVerifiedEmail());

        // The buyer gets an empty wallet and a code to confirm the address.
        $wallet = Wallet::query()->where('user_id', $user->id)->sole();
        $this->assertSame(WalletType::Buyer, $wallet->type);
        $this->assertTrue($wallet->balance->isZero());
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->emailedCode($user, OtpPurpose::EmailVerification));

        $this->assertSame(0, Company::query()->count());
    }

    public function test_a_seller_sign_up_creates_the_company_its_owner_and_its_wallet(): void
    {
        $type = UserType::factory()->seller()->create(['is_default' => true]);

        $response = $this->post('/register', [
            'account_type' => 'seller',
            'name' => 'Test Owner',
            'email' => 'owner@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'company_name' => 'Rajshahi Silk House',
            'phone' => '01700000000',
        ]);

        $owner = User::query()->where('email', 'owner@example.com')->sole();
        $company = Company::query()->sole();

        $response->assertRedirect(route('verification.notice'));
        $this->assertAuthenticatedAs($owner);

        $this->assertSame(UserRole::CompanyOwner, $owner->role);
        $this->assertSame($company->id, $owner->company_id);

        $this->assertSame('Rajshahi Silk House', $company->name);
        $this->assertSame('rajshahi-silk-house', $company->slug);
        $this->assertSame('01700000000', $company->phone);
        $this->assertSame($type->id, $company->user_type_id);
        // Not verified by the platform yet, so it cannot list.
        $this->assertSame(CompanyStatus::Pending, $company->status);
        $this->assertNull($company->verified_at);

        $wallet = Wallet::query()->where('company_id', $company->id)->sole();
        $this->assertSame(WalletType::Company, $wallet->type);
        $this->assertNull($wallet->user_id);
        // The money belongs to the company, not to its owner.
        $this->assertSame(0, Wallet::query()->where('user_id', $owner->id)->count());

        $this->emailedCode($owner, OtpPurpose::EmailVerification);
    }

    public function test_two_companies_with_the_same_name_get_different_addresses(): void
    {
        Company::factory()->create(['name' => 'Silk House', 'slug' => 'silk-house']);

        $this->post('/register', [
            'account_type' => 'seller',
            'name' => 'Test Owner',
            'email' => 'owner@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'company_name' => 'Silk House',
        ]);

        $this->assertSame('silk-house-2', User::query()->where('email', 'owner@example.com')->sole()->company->slug);
    }

    public function test_a_seller_must_name_the_company(): void
    {
        $this->post('/register', [
            'account_type' => 'seller',
            'name' => 'Test Owner',
            'email' => 'owner@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('company_name');

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Company::query()->count());
    }

    public function test_the_account_type_must_be_one_of_the_two_choices(): void
    {
        foreach ([null, 'admin', 'system_owner'] as $type) {
            $this->post('/register', array_filter([
                'account_type' => $type,
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ]))->assertSessionHasErrors('account_type');
        }

        $this->assertSame(0, User::query()->count());
    }

    public function test_a_sign_up_form_cannot_choose_its_role_or_company(): void
    {
        $company = Company::factory()->create();

        $this->post('/register', [
            'account_type' => 'buyer',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            // All ignored.
            'role' => UserRole::SystemOwner->value,
            'company_id' => $company->id,
            'company_name' => 'Ignored for a buyer',
            'is_active' => false,
        ]);

        $user = User::query()->where('email', 'test@example.com')->sole();

        $this->assertSame(UserRole::Buyer, $user->role);
        $this->assertNull($user->company_id);
        $this->assertTrue($user->is_active);
        $this->assertSame(1, Company::query()->count());
    }

    public function test_an_email_address_can_only_be_used_once(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/register', [
            'account_type' => 'buyer',
            'name' => 'Test User',
            'email' => 'taken@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_new_account_is_held_at_the_code_page_until_the_email_is_confirmed(): void
    {
        $this->post('/register', [
            'account_type' => 'buyer',
            'name' => 'Test Buyer',
            'email' => 'buyer@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->get('/dashboard')->assertRedirect(route('verification.notice'));
        $this->get('/profile')->assertRedirect(route('verification.notice'));
        $this->get('/verify-email')->assertOk()->assertSee('buyer@example.com');
    }

    public function test_the_default_user_type_is_looked_up_by_account_kind(): void
    {
        UserType::factory()->seller()->create(['is_default' => true]);

        $this->post('/register', [
            'account_type' => 'buyer',
            'name' => 'Test Buyer',
            'email' => 'buyer@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::query()->where('email', 'buyer@example.com')->sole();

        // A seller type is never given to a buyer, default or not.
        $this->assertNull($user->user_type_id);
    }
}
