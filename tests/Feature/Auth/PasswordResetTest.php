<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpPurpose;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\OtpCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Support\InteractsWithOtp;
use Tests\TestCase;

/** Forgotten password: address, emailed code, new password. */
class PasswordResetTest extends TestCase
{
    use InteractsWithOtp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_the_pages_render(): void
    {
        $this->get('/forgot-password')->assertOk()->assertSee(__('Email me a code'));

        // The code page needs an address first.
        $this->get('/reset-password')->assertRedirect(route('password.request'));
    }

    public function test_a_code_resets_the_password_and_forgets_trusted_browsers(): void
    {
        $user = User::factory()->unverified()->create(['password_change_required' => true]);
        TrustedDevice::factory()->for($user)->create();

        $this->post('/forgot-password', ['email' => strtoupper($user->email)])->assertRedirect(route('password.reset'));
        $this->get('/reset-password')->assertOk()->assertSee($user->email);

        $code = $this->emailedCode($user, OtpPurpose::PasswordReset);

        $this->post('/reset-password', ['code' => $code, 'password' => 'a-new-secret-9', 'password_confirmation' => 'a-new-secret-9'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        $user->refresh();
        $this->assertTrue(Hash::check('a-new-secret-9', $user->password));
        $this->assertFalse($user->password_change_required);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame(0, TrustedDevice::query()->where('user_id', $user->id)->count());
        $this->assertGuest();

        // The code is spent and the reset is over.
        $this->post('/reset-password', ['code' => $code, 'password' => 'another-one-9', 'password_confirmation' => 'another-one-9'])
            ->assertRedirect(route('password.request'));
    }

    public function test_a_wrong_code_changes_nothing(): void
    {
        $user = User::factory()->create();
        $before = $user->password;

        $this->post('/forgot-password', ['email' => $user->email]);
        $code = $this->emailedCode($user, OtpPurpose::PasswordReset);

        $this->from('/reset-password')
            ->post('/reset-password', ['code' => $this->wrongCode($code), 'password' => 'a-new-secret-9', 'password_confirmation' => 'a-new-secret-9'])
            ->assertSessionHasErrors('code');

        $this->assertSame($before, $user->refresh()->password);
    }

    public function test_an_unknown_or_suspended_address_looks_the_same_and_gets_nothing(): void
    {
        $suspended = User::factory()->create(['is_active' => false]);

        $this->post('/forgot-password', ['email' => 'nobody@example.test'])->assertRedirect(route('password.reset'));
        $this->get('/reset-password')->assertOk()->assertSee('nobody@example.test');
        $this->from('/reset-password')
            ->post('/reset-password', ['code' => '123456', 'password' => 'a-new-secret-9', 'password_confirmation' => 'a-new-secret-9'])
            ->assertSessionHasErrors('code');

        $this->post('/forgot-password', ['email' => $suspended->email])->assertRedirect(route('password.reset'));

        Notification::assertNothingSent();
    }

    public function test_an_abandoned_reset_expires(): void
    {
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);
        $code = $this->emailedCode($user, OtpPurpose::PasswordReset);

        $this->travel(16)->minutes();

        $this->post('/reset-password', ['code' => $code, 'password' => 'a-new-secret-9', 'password_confirmation' => 'a-new-secret-9'])
            ->assertRedirect(route('password.request'));
    }

    public function test_a_new_code_can_be_sent_after_the_cooldown(): void
    {
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);
        $this->from('/reset-password')->post('/reset-password/resend')->assertSessionHasErrors('code');

        $this->travel((int) config('auction.otp.resend_seconds') + 1)->seconds();
        $this->from('/reset-password')->post('/reset-password/resend')->assertSessionHasNoErrors();

        Notification::assertSentToTimes($user, OtpCodeNotification::class, 2);
    }
}
