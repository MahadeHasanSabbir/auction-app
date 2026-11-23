<?php

namespace App\Actions\Notifications;

use App\Enums\NotificationCategory;
use App\Models\User;

/** Saves which notifications a user wants, by category and channel. */
final class UpdateNotificationPreferences
{
    /**
     * @param  array<string, array{bell?: bool, mail?: bool, push?: bool}>  $choices  keyed by category value
     */
    public function handle(User $user, array $choices): User
    {
        $preferences = [];

        foreach (NotificationCategory::cases() as $category) {
            $preferences[$category->value] = [
                'bell' => (bool) ($choices[$category->value]['bell'] ?? false),
                'mail' => (bool) ($choices[$category->value]['mail'] ?? false),
                'push' => (bool) ($choices[$category->value]['push'] ?? false),
            ];
        }

        $user->notification_preferences = $preferences;
        $user->save();

        return $user;
    }
}
