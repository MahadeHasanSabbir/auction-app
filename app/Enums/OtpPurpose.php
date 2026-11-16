<?php

namespace App\Enums;

/** What an emailed one-time code may be used for. */
enum OtpPurpose: string
{
    case EmailVerification = 'email_verification';
    case Login = 'login';
    case SensitiveAction = 'sensitive_action';

    public function label(): string
    {
        return match ($this) {
            self::EmailVerification => __('Email verification'),
            self::Login => __('Login'),
            self::SensitiveAction => __('Sensitive action'),
        };
    }
}
