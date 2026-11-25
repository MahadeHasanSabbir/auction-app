<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Staff answered a ticket that came from the public contact form: the answer goes by email, as the sender has no account. */
class GuestTicketReply extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $ticketId, public readonly string $subject, public readonly string $reply) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Re: :subject (ticket #:id)', ['subject' => $this->subject, 'id' => $this->ticketId]))
            ->line(__('Our team answered your message:'))
            ->line($this->reply)
            ->line(__('To write back, use the contact form on our website and mention ticket #:id.', ['id' => $this->ticketId]));
    }
}
