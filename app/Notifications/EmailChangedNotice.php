<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the old address when an account's email address is changed. */
class EmailChangedNotice extends Notification
{
    public function __construct(
        public readonly string $name,
        public readonly string $newEmail,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your email address was changed'))
            ->greeting(__('Hello :name,', ['name' => $this->name]))
            ->line(__('The email address of your :app account was changed to :email.', ['app' => config('app.name'), 'email' => $this->mask($this->newEmail)]))
            ->line(__('If you did not do this, reply to this email or contact support at once.'));
    }

    /** Enough to recognise the address, not enough to harvest it. */
    private function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).str_repeat('*', max(1, mb_strlen($local) - 2)).'@'.$domain;
    }
}
