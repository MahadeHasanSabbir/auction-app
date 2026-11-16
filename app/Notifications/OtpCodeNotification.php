<?php

namespace App\Notifications;

use App\Enums\OtpPurpose;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a one-time code. Sent immediately rather than queued: the person is
 * waiting on the code page, and a stopped queue worker must not lock them out.
 */
class OtpCodeNotification extends Notification
{
    public function __construct(
        public readonly string $code,
        public readonly OtpPurpose $purpose,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auction.otp.ttl_minutes');

        [$subject, $intro] = match ($this->purpose) {
            OtpPurpose::EmailVerification => [
                __('Confirm your email address'),
                __('Enter this code to confirm your email address and finish setting up your account.'),
            ],
            OtpPurpose::Login => [
                __('Your sign-in code'),
                __('Enter this code to finish signing in.'),
            ],
            OtpPurpose::SensitiveAction => [
                __('Confirm it is you'),
                __('Enter this code to confirm a change to your account.'),
            ],
        };

        return (new MailMessage)
            ->subject($subject.' – '.$this->code)
            ->line($intro)
            ->line('**'.$this->code.'**')
            ->line(__('The code is valid for :minutes minutes and can be used once.', ['minutes' => $minutes]))
            ->line(__('If you did not ask for this code, you can ignore this email. Never share the code with anyone.'));
    }
}
