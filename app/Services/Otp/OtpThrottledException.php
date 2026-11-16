<?php

namespace App\Services\Otp;

use RuntimeException;

/** A new code was asked for too soon, or too many times this hour. */
final class OtpThrottledException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct("A new code can be sent in {$retryAfterSeconds} seconds.");
    }

    public function userMessage(): string
    {
        return $this->retryAfterSeconds > 90
            ? __('Too many codes requested. Try again in :minutes minutes.', ['minutes' => (int) ceil($this->retryAfterSeconds / 60)])
            : __('Please wait :seconds seconds before requesting another code.', ['seconds' => $this->retryAfterSeconds]);
    }
}
