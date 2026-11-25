<?php

namespace Tests\Support;

use App\Enums\OtpPurpose;
use App\Models\User;
use App\Notifications\OtpCodeNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Helpers for tests that go through an emailed code. The test must call
 * Notification::fake() first, so the code can be read from the "sent" mail.
 */
trait InteractsWithOtp
{
    /** The newest code emailed to the user for the purpose. */
    protected function emailedCode(User $user, OtpPurpose $purpose): string
    {
        $sent = Notification::sent(
            $user,
            OtpCodeNotification::class,
            fn (OtpCodeNotification $notification): bool => $notification->purpose === $purpose,
        );

        $this->assertNotEmpty($sent, "No {$purpose->value} code was emailed.");

        return $sent->last()->code;
    }

    /** Any six digits that are not the given code. */
    protected function wrongCode(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    /** A session that has already passed the step-up code. */
    protected function withConfirmedOtp(): static
    {
        return $this->withSession(['auth.otp_confirmed_at' => now()->getTimestamp()]);
    }
}
