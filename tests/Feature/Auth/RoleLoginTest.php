<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpPurpose;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\InteractsWithOtp;
use Tests\TestCase;

/**
 * Every kind of account signs in on the same page and lands in its own
 * workspace.
 */
class RoleLoginTest extends TestCase
{
    use InteractsWithOtp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
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
                ->assertRedirect(route('login.code'));

            $this->post('/login/code', ['code' => $this->emailedCode($user, OtpPurpose::Login)])
                ->assertRedirect($destination);

            $this->assertAuthenticatedAs($user);
            $this->assertNotNull($user->refresh()->last_login_at);

            $this->post('/logout');
        }
    }

    public function test_a_guest_sent_to_sign_in_returns_to_the_page_they_wanted(): void
    {
        $user = User::factory()->create();

        $this->get('/profile')->assertRedirect('/login');

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/login/code', ['code' => $this->emailedCode($user, OtpPurpose::Login)])
            ->assertRedirect('/profile');
    }

    public function test_staff_and_sellers_who_open_the_buyer_page_are_sent_to_their_panel(): void
    {
        $this->actingAs(User::factory()->platformStaff()->create())->get('/dashboard')->assertRedirect('/admin');
        $this->actingAs(User::factory()->companyMember()->create())->get('/dashboard')->assertRedirect('/seller');
    }

    public function test_a_deactivated_account_cannot_log_in(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        Notification::assertNothingSent();
    }

    public function test_an_account_deactivated_between_the_two_steps_cannot_finish_signing_in(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $code = $this->emailedCode($user, OtpPurpose::Login);

        $user->forceFill(['is_active' => false])->save();

        $this->post('/login/code', ['code' => $code])->assertRedirect('/login');
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
