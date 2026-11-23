<?php

namespace Tests\Feature\Account;

use App\Actions\Accounts\SuspendAccount;
use App\Enums\FeeUseCase;
use App\Models\Auction;
use App\Models\Company;
use App\Models\CompanyAgreement;
use App\Models\FeeRule;
use App\Models\KycRequest;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\EmailChangedNotice;
use App\Support\OpsAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOtp;
use Tests\TestCase;

/** The Phase 15 audit fixes: sessions, headers, privacy and owner-only money settings. */
class HardeningTest extends TestCase
{
    use InteractsWithOtp;
    use RefreshDatabase;

    public function test_pages_carry_security_headers(): void
    {
        $this->get('/')->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
    }

    public function test_a_suspended_account_is_signed_out_on_its_next_page(): void
    {
        $buyer = User::factory()->create(['remember_token' => 'old-token']);
        $this->actingAs($buyer)->get('/dashboard')->assertOk();

        app(SuspendAccount::class)->handle(User::factory()->platformStaff()->create(), $buyer, 'Testing the sign-out');

        $this->assertNotSame('old-token', $buyer->refresh()->remember_token);
        $this->actingAs($buyer->refresh())->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_changing_the_password_signs_out_other_browsers(): void
    {
        $buyer = User::factory()->create(['remember_token' => 'old-token']);
        DB::table('sessions')->insert(['id' => 'elsewhere', 'user_id' => $buyer->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($buyer)->withConfirmedOtp()
            ->put('/password', ['current_password' => 'password', 'password' => 'a-new-secret-9', 'password_confirmation' => 'a-new-secret-9'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'elsewhere']);
        $this->assertNotSame('old-token', $buyer->refresh()->remember_token);
    }

    public function test_changing_the_email_tells_the_old_address(): void
    {
        Notification::fake();
        $buyer = User::factory()->create(['email' => 'old@example.test']);

        $this->actingAs($buyer)->withConfirmedOtp()->patch('/profile/email', ['email' => 'new@example.test'])->assertRedirect(route('verification.notice'));

        Notification::assertSentOnDemand(EmailChangedNotice::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'old@example.test');
    }

    public function test_company_members_do_not_see_the_owners_identity_documents(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->companyOwner($company)->create();
        $member = User::factory()->companyMember($company)->create();
        $request = KycRequest::factory()->create(['user_id' => null, 'company_id' => $company->id, 'requested_by' => $owner->id]);

        $this->assertTrue(Gate::forUser($owner)->allows('view', $request));
        $this->assertFalse(Gate::forUser($member)->allows('view', $request));
    }

    public function test_an_agreement_in_force_is_ended_not_edited_or_deleted(): void
    {
        $owner = User::factory()->systemOwner()->create();
        $inForce = CompanyAgreement::factory()->create();
        $future = CompanyAgreement::factory()->create(['effective_from' => now()->addWeek()]);

        $this->assertFalse(Gate::forUser($owner)->allows('update', $inForce));
        $this->assertFalse(Gate::forUser($owner)->allows('delete', $inForce));
        $this->assertTrue(Gate::forUser($owner)->allows('end', $inForce));
        $this->assertTrue(Gate::forUser($owner)->allows('delete', $future));
        $this->assertFalse(Gate::forUser($owner)->allows('end', $future));

        // Changes are logged.
        $this->assertDatabaseHas('activity_log', ['log_name' => 'admin', 'subject_type' => 'company_agreement', 'subject_id' => $inForce->id, 'event' => 'created']);
    }

    public function test_the_default_sale_commission_is_the_owners(): void
    {
        $staff = User::factory()->platformStaff()->create();
        $owner = User::factory()->systemOwner()->create();
        $rule = FeeRule::factory()->create(['use_case' => FeeUseCase::SaleCommission]);

        $this->assertFalse(Gate::forUser($staff)->allows('end', $rule));
        $this->assertTrue(Gate::forUser($owner)->allows('end', $rule));
        $this->assertTrue(Gate::forUser($staff)->allows('end', FeeRule::factory()->create()));
    }

    public function test_a_plan_with_invoices_or_subscriptions_cannot_be_deleted(): void
    {
        $owner = User::factory()->systemOwner()->create();

        $this->assertTrue(Gate::forUser($owner)->allows('delete', Plan::factory()->create()));

        $used = Plan::factory()->create();
        Subscription::factory()->create(['plan_id' => $used->id]);
        $this->assertFalse(Gate::forUser($owner)->allows('delete', $used));
    }

    public function test_an_overlong_idempotency_key_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['read', 'write']);

        $auction = Auction::factory()->live()->create();

        $this->withHeader('Idempotency-Key', str_repeat('k', 65))
            ->postJson("/api/v1/auctions/{$auction->id}/bids", ['amount' => '100000'])
            ->assertStatus(422);
    }

    public function test_a_failed_job_is_mailed_to_the_operators_once(): void
    {
        Notification::fake();
        config(['auction.alerts_email' => 'ops@example.test']);

        OpsAlert::send('A queued job failed: X', 'boom');
        OpsAlert::send('A queued job failed: X', 'boom again');

        Notification::assertSentOnDemandTimes(OpsAlert::class, 1);
    }
}
