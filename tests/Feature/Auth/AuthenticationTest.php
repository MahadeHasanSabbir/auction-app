<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpPurpose;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\OtpCodeNotification;
use App\Services\TrustedDevices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\InteractsWithOtp;
use Tests\TestCase;

/**
 * Signing in takes two steps: the password, then a code sent by email,
 * unless the browser was marked as trusted.
 */
class AuthenticationTest extends TestCase
{
    use InteractsWithOtp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_the_password_alone_does_not_sign_anyone_in(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('login.code'));

        $this->assertGuest();
        $this->emailedCode($user, OtpPurpose::Login);

        // Still a guest as far as the rest of the site is concerned.
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login/code')->assertOk()->assertSee($user->email);
    }

    public function test_the_emailed_code_completes_the_sign_in(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->post('/login/code', ['code' => $this->emailedCode($user, OtpPurpose::Login)])
            ->assertRedirect('/dashboard')
            ->assertSessionHas('auth.otp_confirmed_at')
            ->assertSessionMissing('login.challenge');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->last_login_at);
        $this->assertSame(0, TrustedDevice::query()->count());
    }

    public function test_a_wrong_code_does_not_sign_in(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $code = $this->emailedCode($user, OtpPurpose::Login);

        $this->from('/login/code')
            ->post('/login/code', ['code' => $this->wrongCode($code)])
            ->assertRedirect('/login/code')
            ->assertSessionHasErrors('code');

        $this->assertGuest();

        // The real code still works afterwards.
        $this->post('/login/code', ['code' => $code])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_code_page_needs_a_password_step_first(): void
    {
        $this->get('/login/code')->assertRedirect('/login');
        $this->post('/login/code', ['code' => '123456'])->assertRedirect('/login');
        $this->post('/login/code/resend')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_a_half_finished_sign_in_is_abandoned_after_a_while(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $code = $this->emailedCode($user, OtpPurpose::Login);

        $this->travel(16)->minutes();

        $this->post('/login/code', ['code' => $code])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_code_entered_at_sign_in_also_confirms_the_email_address(): void
    {
        $user = User::factory()->unverified()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/login/code', ['code' => $this->emailedCode($user, OtpPurpose::Login)])
            ->assertRedirect('/dashboard');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_trusted_browser_skips_the_code_next_time(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $response = $this->post('/login/code', [
            'code' => $this->emailedCode($user, OtpPurpose::Login),
            'trust_device' => '1',
        ]);

        $token = $response->getCookie(TrustedDevices::COOKIE)->getValue();
        $device = TrustedDevice::query()->where('user_id', $user->id)->sole();

        // Only a hash of the cookie is stored.
        $this->assertSame(hash('sha256', $token), $device->token_hash);
        $this->assertTrue($device->expires_at->isAfter(now()->addDays(29)));

        $this->post('/logout');
        $this->assertGuest();

        $this->withCookie(TrustedDevices::COOKIE, $token)
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/dashboard')
            // A trusted sign-in does not count as a fresh code for sensitive pages.
            ->assertSessionMissing('auth.otp_confirmed_at');

        $this->assertAuthenticatedAs($user);
        Notification::assertSentToTimes($user, OtpCodeNotification::class, 1);
    }

    public function test_a_trusted_browser_is_trusted_for_its_own_account_only(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $token = $this->post('/login/code', [
            'code' => $this->emailedCode($user, OtpPurpose::Login),
            'trust_device' => '1',
        ])->getCookie(TrustedDevices::COOKIE)->getValue();

        $this->post('/logout');

        $this->withCookie(TrustedDevices::COOKIE, $token)
            ->post('/login', ['email' => $other->email, 'password' => 'password'])
            ->assertRedirect(route('login.code'));

        $this->assertGuest();
    }

    public function test_trust_runs_out(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $token = $this->post('/login/code', [
            'code' => $this->emailedCode($user, OtpPurpose::Login),
            'trust_device' => '1',
        ])->getCookie(TrustedDevices::COOKIE)->getValue();

        $this->post('/logout');
        $this->travel(config('auction.otp.trusted_device_days') + 1)->days();

        $this->withCookie(TrustedDevices::COOKIE, $token)
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('login.code'));

        $this->assertGuest();
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        Notification::assertNothingSent();
    }

    public function test_sign_in_attempts_are_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        // Even the right password is refused while locked out.
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/');

        $this->assertGuest();
    }
}
