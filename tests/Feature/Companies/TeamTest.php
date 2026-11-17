<?php

namespace Tests\Feature\Companies;

use App\Actions\Team\AcceptInvitation;
use App\Actions\Team\InviteMember;
use App\Actions\Team\RemoveMember;
use App\Actions\Team\RevokeInvitation;
use App\Enums\CompanyStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Notifications\CompanyInvitationNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\InteractsWithCompanies;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use InteractsWithCompanies;
    use RefreshDatabase;

    /** The link in the last invitation email, set by invite(). */
    private string $lastInviteUrl = '';

    private function invite(User $owner, Company $company, string $email): CompanyInvitation
    {
        Notification::fake();

        $invitation = app(InviteMember::class)->handle($owner, $company, $email);

        Notification::assertSentOnDemand(CompanyInvitationNotification::class, function (CompanyInvitationNotification $notification, array $channels, object $notifiable) use ($email) {
            $this->lastInviteUrl = $notification->url;

            return $notifiable->routes['mail'] === strtolower(trim($email));
        });

        return $invitation;
    }

    public function test_the_owner_invites_by_email_and_the_invitation_holds_a_seat(): void
    {
        [$company, $owner] = $this->company($this->plan(['max_users' => 3]));

        $invitation = $this->invite($owner, $company, ' New.Person@Example.com ');

        $this->assertSame('new.person@example.com', $invitation->email);
        $this->assertTrue($invitation->isPending());
        $this->assertSame(2, $company->seatsUsed());
        $this->assertStringContainsString('/invitations/', $this->lastInviteUrl);
        $this->assertStringNotContainsString($invitation->token_hash, $this->lastInviteUrl, 'Only the hash is stored.');
    }

    public function test_the_seat_limit_counts_pending_invitations(): void
    {
        [$company, $owner] = $this->company($this->plan(['max_users' => 3]));
        User::factory()->companyMember($company)->create();

        $this->invite($owner, $company, 'one@example.com');

        try {
            $this->invite($owner, $company, 'two@example.com');
            $this->fail('A fourth seat was handed out on a three-seat plan.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }

        // Withdrawing the invitation frees the seat.
        app(RevokeInvitation::class)->handle($owner, $company, CompanyInvitation::query()->where('email', 'one@example.com')->firstOrFail());
        $this->invite($owner, $company, 'two@example.com');

        $this->assertSame(3, $company->seatsUsed());
    }

    public function test_a_scheduled_downgrade_limits_new_invitations(): void
    {
        $big = $this->plan(['max_users' => 10]);
        $small = $this->plan(['max_users' => 2]);
        [$company, $owner] = $this->company($big);
        $company->subscription()->firstOrFail()->forceFill(['pending_plan_id' => $small->id])->save();

        $this->invite($owner, $company, 'one@example.com');

        $this->expectException(ValidationException::class);
        $this->invite($owner, $company, 'two@example.com');
    }

    public function test_addresses_that_have_an_account_or_an_invitation_are_refused(): void
    {
        [$company, $owner] = $this->company($this->plan(['max_users' => 10]));
        User::factory()->create(['email' => 'taken@example.com']);
        User::factory()->create(['email' => 'gone@example.com'])->delete();
        $this->invite($owner, $company, 'waiting@example.com');

        foreach (['taken@example.com', 'gone@example.com', 'waiting@example.com', 'not an email'] as $email) {
            try {
                $this->invite($owner, $company, $email);
                $this->fail("{$email} was invited.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('email', $e->errors());
            }
        }
    }

    public function test_nobody_but_the_owner_manages_the_team_and_a_company_without_a_live_plan_cannot_invite(): void
    {
        [$company, $owner] = $this->company($this->plan());
        $member = User::factory()->companyMember($company)->create();

        foreach ([$member, User::factory()->create(), User::factory()->companyOwner()->create()] as $user) {
            try {
                app(InviteMember::class)->handle($user, $company, 'x@example.com');
                $this->fail('Someone but the owner invited.');
            } catch (AuthorizationException) {
            }
        }

        $company->subscription()->firstOrFail()->forceFill(['status' => SubscriptionStatus::Expired])->save();

        $this->expectException(ValidationException::class);
        app(InviteMember::class)->handle($owner, $company, 'x@example.com');
    }

    public function test_a_suspended_company_cannot_invite(): void
    {
        [$company, $owner] = $this->company($this->plan());
        $company->forceFill(['status' => CompanyStatus::Suspended])->save();

        $this->expectException(AuthorizationException::class);

        app(InviteMember::class)->handle($owner, $company, 'x@example.com');
    }

    public function test_the_link_creates_a_member_account_that_is_verified_but_not_signed_in(): void
    {
        [$company, $owner] = $this->company($this->plan());
        $this->invite($owner, $company, 'colleague@example.com');
        $path = parse_url($this->lastInviteUrl, PHP_URL_PATH).'';

        $this->get($path)->assertOk()->assertSee($company->name)->assertSee('colleague@example.com');

        $this->post($path, [
            'name' => 'Colleague',
            'password' => 'a-Strong-pass-1',
            'password_confirmation' => 'a-Strong-pass-1',
        ])->assertRedirect(route('login'));

        $user = User::query()->where('email', 'colleague@example.com')->firstOrFail();
        $this->assertSame(UserRole::CompanyMember, $user->role);
        $this->assertSame($company->id, $user->company_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertGuest();

        // The link works once.
        $this->get($path)->assertOk()->assertSee('no longer valid');
        $this->post($path, ['name' => 'Again', 'password' => 'a-Strong-pass-1', 'password_confirmation' => 'a-Strong-pass-1'])->assertSessionHasErrors('token');
        $this->assertSame(1, User::query()->where('email', 'colleague@example.com')->count());
    }

    public function test_expired_and_revoked_invitations_are_refused(): void
    {
        [$company, $owner] = $this->company($this->plan(['max_users' => 10]));

        $this->invite($owner, $company, 'late@example.com');
        $late = $this->lastInviteUrl;
        $this->invite($owner, $company, 'withdrawn@example.com');
        $withdrawn = $this->lastInviteUrl;

        CompanyInvitation::query()->where('email', 'late@example.com')->update(['expires_at' => now()->subMinute()]);
        app(RevokeInvitation::class)->handle($owner, $company, CompanyInvitation::query()->where('email', 'withdrawn@example.com')->firstOrFail());

        foreach ([$late, $withdrawn] as $url) {
            $this->post(parse_url($url, PHP_URL_PATH).'', ['name' => 'X', 'password' => 'a-Strong-pass-1', 'password_confirmation' => 'a-Strong-pass-1'])->assertSessionHasErrors('token');
        }

        $this->assertNull(app(AcceptInvitation::class)->find('not-a-real-token'));
        $this->assertSame(0, User::query()->where('company_id', $company->id)->where('role', UserRole::CompanyMember)->count());
    }

    public function test_removing_a_member_frees_the_seat_and_signs_them_out(): void
    {
        [$company, $owner] = $this->company($this->plan());
        $member = User::factory()->companyMember($company)->create();
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $member->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);

        app(RemoveMember::class)->handle($owner, $company, $member);

        $this->assertSoftDeleted($member);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $member->id)->count());
        $this->assertSame(1, $company->seatsUsed());
    }

    public function test_the_owner_and_other_companies_people_cannot_be_removed(): void
    {
        [$company, $owner] = $this->company($this->plan());
        $otherMember = User::factory()->companyMember()->create();

        try {
            app(RemoveMember::class)->handle($owner, $company, $owner);
            $this->fail('The owner was removed.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('member', $e->errors());
        }

        $this->expectException(NotFoundHttpException::class);
        app(RemoveMember::class)->handle($owner, $company, $otherMember);
    }
}
