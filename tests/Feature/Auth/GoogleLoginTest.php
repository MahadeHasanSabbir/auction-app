<?php

namespace Tests\Feature\Auth;

use App\Enums\KycStatus;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

/** "Continue with Google": buyers only, no emailed code, accounts linked by verified address. */
class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
    }

    private function google(string $email, string $id = '1001', bool $verified = true): void
    {
        $user = (new GoogleUser)->setRaw(['email_verified' => $verified])->map([
            'id' => $id,
            'name' => 'Google Person',
            'email' => $email,
        ]);

        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn($user);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_the_button_shows_only_when_google_is_configured(): void
    {
        $this->get('/login')->assertSee(__('Continue with Google'));

        config(['services.google.client_id' => null]);
        $this->get('/login')->assertDontSee(__('Continue with Google'));
        $this->get('/auth/google/redirect')->assertNotFound();
    }

    public function test_a_new_google_user_becomes_a_verified_buyer_and_is_signed_in_without_a_code(): void
    {
        $this->google('new.person@gmail.com');

        $this->get('/auth/google/callback?code=x&state=y')->assertRedirect('/dashboard');

        $user = User::query()->where('email', 'new.person@gmail.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->isBuyer());
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(KycStatus::Requested, $user->kyc_status);
        $this->assertTrue(Wallet::query()->where('user_id', $user->id)->exists());
        $this->assertSame('1001', SocialAccount::query()->where('user_id', $user->id)->value('provider_id'));

        // The step-up pages still want a code.
        $this->get('/profile/security')->assertRedirect(route('otp.confirm'));
    }

    public function test_an_existing_buyer_is_linked_by_address_and_found_by_id_afterwards(): void
    {
        $buyer = User::factory()->create(['email' => 'buyer@example.test']);
        $this->google('buyer@example.test', '2002');

        $this->get('/auth/google/callback')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($buyer);
        $this->assertSame(1, SocialAccount::query()->count());

        auth()->logout();
        $buyer->forceFill(['email' => 'changed@example.test'])->save();
        $this->google('someone-else@gmail.com', '2002');

        $this->get('/auth/google/callback')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($buyer);
        $this->assertSame(1, User::query()->count());
    }

    public function test_sellers_staff_suspended_and_unverified_addresses_are_refused(): void
    {
        $staff = User::factory()->platformStaff()->create(['email' => 'staff@example.test']);
        $this->google($staff->email);
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();

        User::factory()->create(['email' => 'off@example.test', 'is_active' => false]);
        $this->google('off@example.test', '3003');
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->google('unverified@gmail.com', '4004', verified: false);
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(0, SocialAccount::query()->count());
        $this->assertFalse(User::query()->where('email', 'unverified@gmail.com')->exists());
    }
}
