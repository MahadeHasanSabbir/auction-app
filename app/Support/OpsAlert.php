<?php

namespace App\Support;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as Notifications;

/**
 * Mails an operational problem (a failed queue job, a failed backup) to
 * auction.alerts_email. The same subject is mailed at most once every ten
 * minutes, so a broken worker cannot flood the inbox; everything is logged.
 */
final class OpsAlert extends Notification
{
    private function __construct(
        public readonly string $subject,
        public readonly string $body,
    ) {}

    public static function send(string $subject, string $body): void
    {
        Log::error($subject, ['detail' => $body]);

        $to = config('auction.alerts_email');

        if (! is_string($to) || $to === '' || ! Cache::add('ops-alert:'.sha1($subject), true, now()->addMinutes(10))) {
            return;
        }

        try {
            Notifications::route('mail', $to)->notifyNow(new self($subject, $body));
        } catch (\Throwable $e) {
            Log::error('The operations alert could not be mailed.', ['error' => $e->getMessage()]);
        }
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('['.config('app.name').'] '.$this->subject)
            ->line($this->subject)
            ->line(mb_substr($this->body, 0, 2000))
            ->line('Server time: '.now()->toDateTimeString().' UTC');
    }
}
