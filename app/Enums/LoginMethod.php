<?php

namespace App\Enums;

/** How a sign-in attempt was made. */
enum LoginMethod: string
{
    case Code = 'code';
    case TrustedDevice = 'trusted_device';
    case Google = 'google';
    case Password = 'password';

    public function label(): string
    {
        return match ($this) {
            self::Code => __('Password and emailed code'),
            self::TrustedDevice => __('Password on a trusted browser'),
            self::Google => __('Google'),
            self::Password => __('Password'),
        };
    }
}
