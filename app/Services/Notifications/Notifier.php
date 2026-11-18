<?php

namespace App\Services\Notifications;

use App\Enums\NotificationKind;
use App\Models\Company;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Sends a notification to some people. The recipient's own switches decide
 * whether it reaches their bell, their inbox or both (AppNotification::via),
 * so callers only say what happened and who should know.
 */
class Notifier
{
    /**
     * @param  User|int|iterable<User|int|null>|null  $recipients
     */
    public function send(User|int|iterable|null $recipients, NotificationKind $kind, string $title, string $body, string $url): void
    {
        $users = $this->resolve($recipients);

        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, new AppNotification($kind, $title, $body, $url));
    }

    /**
     * Everyone on a company's team who can still sign in.
     *
     * @return Collection<int, User>
     */
    public function team(Company|int $company): Collection
    {
        $id = $company instanceof Company ? $company->id : $company;

        return User::query()->where('company_id', $id)->where('is_active', true)->get();
    }

    /**
     * @param  User|int|iterable<User|int|null>|null  $recipients
     * @return Collection<int, User>
     */
    private function resolve(User|int|iterable|null $recipients): Collection
    {
        $items = $recipients === null ? [] : ($recipients instanceof User || is_int($recipients) ? [$recipients] : $recipients);

        $ids = [];
        $users = [];

        foreach ($items as $item) {
            if ($item instanceof User) {
                $users[$item->id] = $item;
            } elseif (is_int($item)) {
                $ids[] = $item;
            }
        }

        $missing = array_diff($ids, array_keys($users));

        if ($missing !== []) {
            foreach (User::query()->whereIn('id', $missing)->get() as $user) {
                $users[$user->id] = $user;
            }
        }

        return collect($users)->filter(fn (User $user) => $user->is_active)->values();
    }
}
