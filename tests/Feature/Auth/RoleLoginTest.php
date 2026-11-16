<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_a_buyer(): void
    {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            // Ignored: a sign-up form cannot choose its own role.
            'role' => UserRole::SystemOwner->value,
        ]);

        $user = User::query()->where('email', 'test@example.com')->sole();

        $this->assertSame(UserRole::Buyer, $user->role);
        $this->assertNull($user->company_id);
    }

    public function test_each_kind_of_account_lands_in_its_own_workspace(): void
    {
        $cases = [
            '/admin' => User::factory()->systemOwner()->create(),
            '/seller' => User::factory()->companyOwner()->create(),
            '/dashboard' => User::factory()->create(),
        ];

        foreach ($cases as $destination => $user) {
            $this->post('/login', ['email' => $user->email, 'password' => 'password'])
                ->assertRedirect($destination);

            $this->assertAuthenticatedAs($user);
            $this->assertNotNull($user->refresh()->last_login_at);

            $this->post('/logout');
        }
    }

    public function test_a_deactivated_account_cannot_log_in(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_deleted_account_cannot_log_in(): void
    {
        $user = User::factory()->create();
        $user->delete();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
