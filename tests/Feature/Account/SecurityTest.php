<?php

namespace Tests\Feature\Account;

use App\Enums\OtpPurpose;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\InteractsWithOtp;
use Tests\TestCase;

/**
 * The sensitive account settings sit behind a fresh emailed code.
 */
class SecurityTest extends TestCase
{
    use InteractsWithOtp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_the_security_page_asks_for_a_code_first(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile/security')->assertRedirect(route('otp.confirm'));

        // Opening the code page sends the code.
        $this->get('/confirm-code')->assertOk()->assertSee($user->email);
        $code = $this->emailedCode($user, OtpPurpose::SensitiveAction);

        $this->from('/confirm-code')
            ->post('/confirm-code', ['code' => $this->wrongCode($code)])
            ->assertRedirect('/confirm-code')
            ->assertSessionHasErrors('code');

        $this->get('/profile/security')->assertRedirect(route('otp.confirm'));

        // The right code opens the page that was asked for.
        $this->post('/confirm-code', ['code' => $code])->assertRedirect('/profile/security');
        $this->get('/profile/security')->assertOk();
    }

    public function test_reloading_the_code_page_does_not_send_another_code(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/confirm-code')->assertOk();
        $this->get('/confirm-code')->assertOk();

        Notification::assertCount(1);
    }

    public function test_the_confirmation_wears_off(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withConfirmedOtp()->get('/profile/security')->assertOk();

        $this->travel(config('auction.otp.confirm_minutes') + 1)->minutes();

        $this->get('/profile/security')->assertRedirect(route('otp.confirm'));
    }

    public function test_every_sensitive_action_is_refused_without_a_fresh_code(): void
    {
        $user = User::factory()->create();
        TrustedDevice::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user);

        $this->patch('/profile/email', ['email' => 'new@example.com'])->assertRedirect(route('otp.confirm'));
        $this->delete('/profile/devices')->assertRedirect(route('otp.confirm'));
        $this->delete('/profile', ['password' => 'password'])->assertRedirect(route('otp.confirm'));

        $user->refresh();
        $this->assertNotSame('new@example.com', $user->email);
        $this->assertFalse($user->trashed());
        $this->assertSame(1, TrustedDevice::query()->count());
    }

    public function test_guests_cannot_reach_the_security_pages(): void
    {
        $this->get('/profile/security')->assertRedirect('/login');
        $this->get('/confirm-code')->assertRedirect('/login');
        $this->post('/confirm-code', ['code' => '123456'])->assertRedirect('/login');
    }

    public function test_a_new_email_address_has_to_be_confirmed_before_the_account_can_continue(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->actingAs($user)
            ->withConfirmedOtp()
            ->patch('/profile/email', ['email' => 'new@example.com'])
            ->assertRedirect(route('verification.notice'));

        $user->refresh();
        $this->assertSame('new@example.com', $user->email);
        $this->assertFalse($user->hasVerifiedEmail());

        // Held at the code page; the code went to the new address.
        $this->get('/dashboard')->assertRedirect(route('verification.notice'));
        $code = $this->emailedCode($user, OtpPurpose::EmailVerification);

        $this->post('/verify-email', ['code' => $code]);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_the_email_address_cannot_be_changed_to_one_already_in_use(): void
    {
        $user = User::factory()->create();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($user)
            ->withConfirmedOtp()
            ->patch('/profile/email', ['email' => 'taken@example.com'])
            ->assertSessionHasErrorsIn('updateEmail', 'email');

        $this->assertNotSame('taken@example.com', $user->refresh()->email);
    }

    public function test_keeping_the_same_address_changes_nothing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withConfirmedOtp()
            ->from('/profile/security')
            ->patch('/profile/email', ['email' => $user->email])
            ->assertRedirect('/profile/security');

        $this->assertTrue($user->refresh()->hasVerifiedEmail());
        Notification::assertNothingSent();
    }

    public function test_trusted_browsers_are_listed_and_can_be_removed(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        TrustedDevice::factory()->create(['user_id' => $user->id, 'user_agent' => 'Firefox on Linux']);
        TrustedDevice::factory()->expired()->create(['user_id' => $user->id, 'user_agent' => 'Expired browser']);
        TrustedDevice::factory()->create(['user_id' => $other->id, 'user_agent' => 'Somebody else']);

        $this->actingAs($user)
            ->withConfirmedOtp()
            ->get('/profile/security')
            ->assertOk()
            ->assertSee('Firefox on Linux')
            ->assertDontSee('Expired browser')
            ->assertDontSee('Somebody else');

        $this->delete('/profile/devices')->assertSessionHasNoErrors();

        $this->assertSame(0, TrustedDevice::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, TrustedDevice::query()->where('user_id', $other->id)->count());
    }

    public function test_a_buyer_with_an_empty_wallet_can_close_the_account(): void
    {
        $user = User::factory()->create();
        Wallet::factory()->create(['user_id' => $user->id]);
        TrustedDevice::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->withConfirmedOtp()
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        // Soft deleted: gone for sign-in, kept for its history.
        $this->assertSoftDeleted($user);
        $this->assertNull(User::query()->find($user->id));
        $this->assertSame(0, TrustedDevice::query()->count());
    }

    public function test_an_account_with_money_in_the_wallet_cannot_be_closed(): void
    {
        foreach ([['balance' => 50_000, 'held' => 0], ['balance' => 50_000, 'held' => 50_000]] as $state) {
            $user = User::factory()->create();
            Wallet::factory()->create(['user_id' => $user->id, ...$state]);

            $this->actingAs($user)
                ->withConfirmedOtp()
                ->delete('/profile', ['password' => 'password'])
                ->assertSessionHasErrorsIn('userDeletion', 'password');

            $this->assertAuthenticatedAs($user);
            $this->assertFalse($user->refresh()->trashed());
        }
    }

    public function test_the_password_is_needed_to_close_the_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withConfirmedOtp()
            ->from('/profile/security')
            ->delete('/profile', ['password' => 'wrong-password'])
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile/security');

        $this->assertFalse($user->refresh()->trashed());
    }

    public function test_staff_and_company_accounts_cannot_close_themselves(): void
    {
        $accounts = [
            User::factory()->systemOwner()->create(),
            User::factory()->platformStaff()->create(),
            User::factory()->companyOwner()->create(),
            User::factory()->companyMember()->create(),
        ];

        foreach ($accounts as $user) {
            $this->actingAs($user)
                ->withConfirmedOtp()
                ->delete('/profile', ['password' => 'password'])
                ->assertForbidden();

            $this->assertFalse($user->refresh()->trashed());

            // And the page does not offer it.
            $this->get('/profile/security')->assertOk()->assertDontSee('Close my account');
        }
    }
}
