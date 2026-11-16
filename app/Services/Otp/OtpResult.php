<?php

namespace App\Services\Otp;

/** The outcome of checking a one-time code. */
enum OtpResult
{
    case Verified;
    case Invalid;
    case Expired;
    case TooManyAttempts;

    public function passed(): bool
    {
        return $this === self::Verified;
    }

    /** What to tell the person who typed the code. */
    public function message(): string
    {
        return match ($this) {
            self::Verified => __('Code accepted.'),
            self::Invalid => __('That code is not correct.'),
            self::Expired => __('That code has expired. Request a new one.'),
            self::TooManyAttempts => __('Too many wrong attempts. Request a new code.'),
        };
    }
}
