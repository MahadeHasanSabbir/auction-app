<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\RedirectResponse;

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

    /**
     * After signing in or confirming the email: back to the page the user
     * was stopped at, unless that page is in a panel their role cannot open
     * (a seller page left in the session, then an admin signs in), in which
     * case to their own workspace.
     */
    public static function redirect(User $user): RedirectResponse
    {
        $intended = session()->get('url.intended');

        if (is_string($intended) && ! self::canOpen($user, $intended)) {
            session()->forget('url.intended');
        }

        return redirect()->intended(self::pathFor($user));
    }

    private static function canOpen(User $user, string $url): bool
    {
        $path = '/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        return match (true) {
            $path === '/admin' || str_starts_with($path, '/admin/') => $user->isPlatform(),
            $path === '/seller' || str_starts_with($path, '/seller/') => $user->isSeller(),
            default => true,
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
