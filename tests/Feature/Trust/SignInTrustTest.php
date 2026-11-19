<?php

namespace Tests\Feature\Trust;

use App\Enums\BlacklistType;
use App\Enums\LoginMethod;
use App\Enums\OtpPurpose;
use App\Filament\Admin\Resources\Blacklist\Pages\ManageBlacklistEntries;
use App\Models\BlacklistEntry;
use App\Models\LoginEvent;
use App\Models\User;
use App\Models\UserType;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\InteractsWithOtp;
use Tests\TestCase;

/** Sign-in history and the blacklist. */
class SignInTrustTest extends TestCase
{
    use InteractsWithOtp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function signIn(User $user): void
    {
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/login/code', ['code' => $this->emailedCode($user, OtpPurpose::Login)]);
    }

    public function test_sign_ins_and_wrong_passwords_are_recorded(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'], ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0'])
            ->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong']);
        $this->signIn($user);

        $events = LoginEvent::query()->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertFalse($events[0]->succeeded);
        $this->assertSame('Chrome on Windows', $events[0]->browser());
        $this->assertTrue($events[1]->succeeded);
        $this->assertSame(LoginMethod::Code, $events[1]->method);
        $this->assertSame($user->id, $events[1]->user_id);
        $this->assertSame('127.0.0.1', $events[1]->ip_address);

        $this->withConfirmedOtp()->get(route('profile.security'))
            ->assertOk()
            ->assertSee(__('Recent sign-ins'))
            ->assertSee(__('Wrong password'));
    }

    public function test_old_sign_ins_are_pruned(): void
    {
        LoginEvent::factory()->create(['created_at' => now()->subDays((int) config('auction.trust.login_history_days') + 1)]);
        $recent = LoginEvent::factory()->create();

        Artisan::call('model:prune', ['--model' => [LoginEvent::class]]);

        $this->assertSame([$recent->id], LoginEvent::query()->pluck('id')->all());
    }

    public function test_a_blacklisted_address_domain_or_ip_cannot_sign_up(): void
    {
        UserType::factory()->create(['is_default' => true]);
        $staff = User::factory()->platformStaff()->create();

        $register = fn (string $email) => $this->post('/register', [
            'account_type' => 'buyer',
            'name' => 'Someone',
            'email' => $email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        BlacklistEntry::factory()->create(['type' => BlacklistType::Email, 'value' => 'Banned@Example.com', 'created_by' => $staff->id]);
        BlacklistEntry::factory()->create(['type' => BlacklistType::Domain, 'value' => '@spam.test', 'created_by' => $staff->id]);

        $register('banned@example.com')->assertSessionHasErrors('email');
        $register('anyone@spam.test')->assertSessionHasErrors('email');
        $this->assertGuest();

        BlacklistEntry::factory()->create(['type' => BlacklistType::Ip, 'value' => '127.0.0.1', 'created_by' => $staff->id, 'expires_at' => now()->subMinute()]);
        $register('fine@example.com')->assertRedirect(route('verification.notice'));
        $this->post('/logout');

        BlacklistEntry::query()->where('type', BlacklistType::Ip)->update(['expires_at' => null]);
        $register('other@example.com')->assertSessionHasErrors('email');
        $this->assertSame(1, User::query()->where('role', 'buyer')->count());
    }

    public function test_a_blacklisted_account_fails_to_sign_in_like_a_wrong_password(): void
    {
        $user = User::factory()->create();
        BlacklistEntry::factory()->create(['type' => BlacklistType::Email, 'value' => $user->email]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->assertGuest();
        $this->assertFalse(LoginEvent::query()->sole()->succeeded);
    }

    public function test_staff_keep_the_blacklist(): void
    {
        $staff = User::factory()->platformStaff()->create();
        Filament::setCurrentPanel('admin');
        $this->actingAs($staff);

        Livewire::test(ManageBlacklistEntries::class)
            ->callAction('create', ['type' => BlacklistType::Domain->value, 'value' => 'Spam.TEST', 'reason' => 'Throwaway addresses'])
            ->assertHasNoActionErrors();

        $entry = BlacklistEntry::query()->sole();
        $this->assertSame('spam.test', $entry->value);
        $this->assertSame($staff->id, $entry->created_by);

        Livewire::test(ManageBlacklistEntries::class)
            ->callAction('create', ['type' => BlacklistType::Ip->value, 'value' => 'not-an-ip', 'reason' => 'x'])
            ->assertHasActionErrors(['value']);

        $this->actingAs(User::factory()->create())->get('/admin/blacklist')->assertForbidden();
    }
}
