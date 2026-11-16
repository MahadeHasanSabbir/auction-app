<?php

namespace Tests\Feature\Auth;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Support\InteractsWithOtp;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use InteractsWithOtp;
    use RefreshDatabase;

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withConfirmedOtp()
            ->from('/profile/security')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile/security');

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_changing_the_password_forgets_every_trusted_browser(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        TrustedDevice::factory()->count(2)->create(['user_id' => $user->id]);
        TrustedDevice::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user)
            ->withConfirmedOtp()
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, TrustedDevice::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, TrustedDevice::query()->where('user_id', $other->id)->count());
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withConfirmedOtp()
            ->from('/profile/security')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertSessionHasErrorsIn('updatePassword', 'current_password')
            ->assertRedirect('/profile/security');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_the_password_cannot_be_changed_without_a_fresh_code(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile/security')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertRedirect(route('otp.confirm'));

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }
}
