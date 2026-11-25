<?php

namespace App\Enums;

enum ConversationType: string
{
    case Order = 'order';
    case Support = 'support';

    public function label(): string
    {
        return match ($this) {
            self::Order => __('Order chat'),
            self::Support => __('Support ticket'),
        };
    }
}
