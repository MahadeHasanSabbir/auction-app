<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\OtpCodeNotification;
use App\Services\Otp\OtpResult;
use App\Services\Otp\OtpService;
use App\Services\Otp\OtpThrottledException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\InteractsWithOtp;
use Tests\TestCase;

class OtpServiceTest extends TestCase
{
    use InteractsWithOtp;
    use RefreshDatabase;

    private OtpService $otp;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->otp = app(OtpService::class);
        $this->user = User::factory()->create();
    }

    public function test_a_code_is_emailed_and_stored_only_as_a_hash(): void
    {
        $stored = $this->otp->send($this->user, OtpPurpose::Login, '203.0.113.9');
        $code = $this->emailedCode($this->user, OtpPurpose::Login);

        Notification::assertSentToTimes($this->user, OtpCodeNotification::class, 1);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertNotSame($code, $stored->code_hash);
        $this->assertStringNotContainsString($code, $stored->code_hash);
        $this->assertSame($this->user->email, $stored->sent_to);
        $this->assertSame('203.0.113.9', $stored->ip_address);
        $this->assertTrue($stored->expires_at->isFuture());
    }

    public function test_the_right_code_passes_once_and_cannot_be_reused(): void
    {
        $this->otp->send($this->user, OtpPurpose::Login);
        $code = $this->emailedCode($this->user, OtpPurpose::Login);

        $this->assertSame(OtpResult::Verified, $this->otp->verify($this->user, OtpPurpose::Login, $code));
        $this->assertSame(OtpResult::Invalid, $this->otp->verify($this->user, OtpPurpose::Login, $code));
        $this->assertFalse($this->otp->hasLiveCode($this->user, OtpPurpose::Login));
    }

    public function test_a_wrong_code_fails_and_leaves_the_real_one_usable(): void
    {
        $this->otp->send($this->user, OtpPurpose::Login);
        $code = $this->emailedCode($this->user, OtpPurpose::Login);

        $this->assertSame(OtpResult::Invalid, $this->otp->verify($this->user, OtpPurpose::Login, $this->wrongCode($code)));
        $this->assertSame(OtpResult::Verified, $this->otp->verify($this->user, OtpPurpose::Login, " {$code} "));
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $this->otp->send($this->user, OtpPurpose::Login);
        $code = $this->emailedCode($this->user, OtpPurpose::Login);

        $this->travel(config('auction.otp.ttl_minutes') + 1)->minutes();

        $this->assertSame(OtpResult::Expired, $this->otp->verify($this->user, OtpPurpose::Login, $code));
    }

    public function test_the_code_dies_after_too_many_wrong_guesses(): void
    {
        $this->otp->send($this->user, OtpPurpose::Login);
        $code = $this->emailedCode($this->user, OtpPurpose::Login);
        $wrong = $this->wrongCode($code);
        $allowed = (int) config('auction.otp.max_attempts');

        for ($guess = 1; $guess < $allowed; $guess++) {
            $this->assertSame(OtpResult::Invalid, $this->otp->verify($this->user, OtpPurpose::Login, $wrong));
        }

        $this->assertSame(OtpResult::TooManyAttempts, $this->otp->verify($this->user, OtpPurpose::Login, $wrong));

        // Even the right code no longer works; a new one has to be requested.
        $this->assertSame(OtpResult::TooManyAttempts, $this->otp->verify($this->user, OtpPurpose::Login, $code));
    }

    public function test_a_code_only_works_for_its_own_purpose_and_user(): void
    {
        $this->otp->send($this->user, OtpPurpose::Login);
        $code = $this->emailedCode($this->user, OtpPurpose::Login);
        $other = User::factory()->create();

        $this->assertSame(OtpResult::Invalid, $this->otp->verify($this->user, OtpPurpose::SensitiveAction, $code));
        $this->assertSame(OtpResult::Invalid, $this->otp->verify($other, OtpPurpose::Login, $code));
        $this->assertSame(OtpResult::Verified, $this->otp->verify($this->user, OtpPurpose::Login, $code));
    }

    public function test_a_new_code_cannot_be_requested_during_the_cooldown(): void
    {
        $this->otp->send($this->user, OtpPurpose::Login);

        try {
            $this->otp->send($this->user, OtpPurpose::Login);
            $this->fail('A second code was sent inside the cooldown.');
        } catch (OtpThrottledException $e) {
            $this->assertGreaterThan(0, $e->retryAfterSeconds);
            $this->assertLessThanOrEqual((int) config('auction.otp.resend_seconds'), $e->retryAfterSeconds);
        }

        // Another purpose has its own cooldown.
        $this->otp->send($this->user, OtpPurpose::SensitiveAction);

        $this->travel(config('auction.otp.resend_seconds') + 1)->seconds();

        $this->otp->send($this->user, OtpPurpose::Login);
        $this->assertSame(2, OtpCode::query()->where('purpose', OtpPurpose::Login)->count());
    }

    public function test_sending_a_new_code_retires_the_previous_one(): void
    {
        $this->otp->send($this->user, OtpPurpose::Login);
        $first = $this->emailedCode($this->user, OtpPurpose::Login);

        $this->travel(config('auction.otp.resend_seconds') + 1)->seconds();

        $this->otp->send($this->user, OtpPurpose::Login);
        $second = $this->emailedCode($this->user, OtpPurpose::Login);

        if ($first !== $second) {
            $this->assertSame(OtpResult::Invalid, $this->otp->verify($this->user, OtpPurpose::Login, $first));
        }

        $this->assertSame(OtpResult::Verified, $this->otp->verify($this->user, OtpPurpose::Login, $second));
        $this->assertSame(2, OtpCode::query()->whereNotNull('consumed_at')->count());
    }

    public function test_only_a_few_codes_can_be_requested_per_hour(): void
    {
        $perHour = (int) config('auction.otp.max_per_hour');
        $gap = (int) config('auction.otp.resend_seconds') + 1;

        for ($sent = 0; $sent < $perHour; $sent++) {
            $this->otp->send($this->user, OtpPurpose::Login);
            $this->travel($gap)->seconds();
        }

        try {
            $this->otp->send($this->user, OtpPurpose::Login);
            $this->fail('More codes than the hourly allowance were sent.');
        } catch (OtpThrottledException $e) {
            // Well beyond the short resend cooldown: the hourly limit is what blocks.
            $this->assertGreaterThan($gap, $e->retryAfterSeconds);
        }

        $this->travel(1)->hours();

        $this->otp->send($this->user, OtpPurpose::Login);
        $this->assertSame($perHour + 1, OtpCode::query()->count());
    }
}
