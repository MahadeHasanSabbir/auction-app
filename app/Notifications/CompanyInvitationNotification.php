<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells someone a company has invited them to its team. Sent to the
 * address as given, so the recipient does not need an account yet.
 */
class CompanyInvitationNotification extends Notification
{
    public function __construct(
        public readonly Company $company,
        public readonly string $inviterName,
        public readonly string $url,
        public readonly int $validDays,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(':company invited you to join its team', ['company' => $this->company->name]))
            ->line(__(':name invited you to sell on :app as part of :company.', [
                'name' => $this->inviterName,
                'app' => config('app.name'),
                'company' => $this->company->name,
            ]))
            ->action(__('Accept the invitation'), $this->url)
            ->line(__('The link works for :days days. If you were not expecting this, you can ignore this email.', ['days' => $this->validDays]));
    }
}
