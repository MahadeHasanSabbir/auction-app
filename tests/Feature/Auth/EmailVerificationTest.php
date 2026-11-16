<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpPurpose;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Support\InteractsWithOtp;
use Tests\TestCase;

/**
 * The email address is confirmed with an emailed code, not a link.
 */
class EmailVerificationTest extends TestCase
{
    use InteractsWithOtp;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->user = User::factory()->unverified()->create();
        $this->user->sendEmailVerificationNotification();
    }

    public function test_the_code_page_is_shown_to_an_unconfirmed_account(): void
    {
        $this->actingAs($this->user)
            ->get('/verify-email')
            ->assertOk()
            ->assertSee($this->user->email);
    }

    public function test_the_right_code_confirms_the_address(): void
    {
        Event::fake([Verified::class]);

        $this->actingAs($this->user)
            ->post('/verify-email', ['code' => $this->emailedCode($this->user, OtpPurpose::EmailVerification)])
            ->assertRedirect('/dashboard')
            ->assertSessionHas('auth.otp_confirmed_at');

        Event::assertDispatched(Verified::class);
        $this->assertTrue($this->user->fresh()->hasVerifiedEmail());

        $this->get('/dashboard')->assertOk();
    }

    public function test_a_wrong_code_does_not_confirm_the_address(): void
    {
        $code = $this->emailedCode($this->user, OtpPurpose::EmailVerification);

        $this->actingAs($this->user)
            ->from('/verify-email')
            ->post('/verify-email', ['code' => $this->wrongCode($code)])
            ->assertRedirect('/verify-email')
            ->assertSessionHasErrors('code');

        $this->assertFalse($this->user->fresh()->hasVerifiedEmail());
    }

    public function test_an_expired_code_does_not_confirm_the_address(): void
    {
        $code = $this->emailedCode($this->user, OtpPurpose::EmailVerification);

        $this->travel(config('auction.otp.ttl_minutes') + 1)->minutes();

        $this->actingAs($this->user)
            ->post('/verify-email', ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertFalse($this->user->fresh()->hasVerifiedEmail());
    }

    public function test_a_new_code_can_be_requested_after_the_cooldown_only(): void
    {
        $this->actingAs($this->user)
            ->post('/email/verification-notification')
            ->assertSessionHasErrors('code');

        $this->travel(config('auction.otp.resend_seconds') + 1)->seconds();

        $this->actingAs($this->user)
            ->post('/email/verification-notification')
            ->assertSessionHasNoErrors();

        $this->actingAs($this->user)
            ->post('/verify-email', ['code' => $this->emailedCode($this->user, OtpPurpose::EmailVerification)])
            ->assertRedirect('/dashboard');
    }

    public function test_a_confirmed_account_is_sent_on_from_the_code_page(): void
    {
        $verified = User::factory()->create();

        $this->actingAs($verified)->get('/verify-email')->assertRedirect('/dashboard');
        $this->actingAs($verified)->post('/verify-email', ['code' => '123456'])->assertRedirect('/dashboard');
    }

    public function test_guests_cannot_reach_the_code_page(): void
    {
        $this->get('/verify-email')->assertRedirect('/login');
        $this->post('/verify-email', ['code' => '123456'])->assertRedirect('/login');
    }

    public function test_unconfirmed_staff_and_sellers_are_held_out_of_their_panels(): void
    {
        $this->actingAs(User::factory()->platformStaff()->unverified()->create())
            ->get('/admin')
            ->assertRedirect(route('verification.notice'));

        $owner = User::factory()->companyOwner()->unverified()->create();

        $this->actingAs($owner)
            ->get('/seller/'.$owner->company()->value('slug'))
            ->assertRedirect(route('verification.notice'));
    }
}
