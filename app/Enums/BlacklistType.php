<?php

namespace App\Enums;

/** What a blacklist entry blocks. */
enum BlacklistType: string
{
    case Email = 'email';
    case Domain = 'domain';
    case Ip = 'ip';

    public function label(): string
    {
        return match ($this) {
            self::Email => __('Email address'),
            self::Domain => __('Email domain'),
            self::Ip => __('IP address'),
        };
    }
}
