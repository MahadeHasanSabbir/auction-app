<?php

namespace App\Support;

use App\Models\User;

/**
 * Where each kind of account works: platform roles in the admin panel,
 * company roles in the seller panel, buyers in their account area.
 */
final class Home
{
    public static function pathFor(User $user): string
    {
        return match (true) {
            $user->isPlatform() => '/admin',
            $user->isSeller() => '/seller',
            default => route('dashboard', absolute: false),
        };
    }

    /** @return array{url: string, label: string} */
    public static function linkFor(User $user): array
    {
        return [
            'url' => url(self::pathFor($user)),
            'label' => match (true) {
                $user->isPlatform() => __('Admin panel'),
                $user->isSeller() => __('Seller panel'),
                default => __('My account'),
            },
        ];
    }
}
