<?php

namespace App\Enums;

enum DisputeReason: string
{
    case NotReceived = 'not_received';
    case NotAsDescribed = 'not_as_described';
    case Damaged = 'damaged';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NotReceived => __('The item has not arrived'),
            self::NotAsDescribed => __('The item is not as described'),
            self::Damaged => __('The item arrived damaged'),
            self::Other => __('Something else'),
        };
    }
}
