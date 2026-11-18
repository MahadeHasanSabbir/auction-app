<?php

namespace App\Actions\Support;

use App\Actions\Messaging\Concerns\PostsMessages;
use App\Enums\ConversationStatus;
use App\Enums\ConversationType;
use App\Enums\NotificationKind;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\GuestTicketReply;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * A reply on a support ticket, by the requester or by platform staff. A reply
 * reopens a closed ticket. The other side is told: the requester by bell and
 * email (a visitor without an account by email only), staff who took the
 * ticket by bell.
 */
final class ReplyToTicket
{
    use PostsMessages;

    public function __construct(private readonly Notifier $notifier) {}

    public function handle(User $author, Conversation $ticket, string $body): Message
    {
        if ($ticket->type !== ConversationType::Support) {
            throw ValidationException::withMessages(['body' => __('This is not a support ticket.')]);
        }

        Gate::forUser($author)->authorize('post', $ticket);

        $body = $this->cleanBody($body, (int) config('auction.messaging.ticket_max'));
        $this->throttle($author);

        $message = DB::transaction(function () use ($author, $ticket, $body): Message {
            $locked = Conversation::query()->lockForUpdate()->findOrFail($ticket->id);

            if ($locked->status === ConversationStatus::Closed) {
                $locked->forceFill(['status' => ConversationStatus::Open, 'closed_at' => null])->save();
            }

            $message = $this->append($locked, $author, $body);
            $ticket->setRawAttributes($locked->getAttributes(), true);

            return $message;
        });

        $this->tellTheOtherSide($author, $ticket, $body);

        return $message;
    }

    private function tellTheOtherSide(User $author, Conversation $ticket, string $body): void
    {
        $subject = (string) $ticket->subject;

        if ($author->isPlatform()) {
            if ($ticket->opened_by === null) {
                if ($ticket->guest_email !== null) {
                    Notification::route('mail', $ticket->guest_email)->notify(new GuestTicketReply($ticket->id, $subject, $body));
                }

                return;
            }

            $this->notifier->send($ticket->opened_by, NotificationKind::TicketReply,
                __('Support answered: :subject', ['subject' => $subject]),
                $body,
                route('support.show', $ticket));

            return;
        }

        if ($ticket->assigned_to !== null && $this->shouldNotify($ticket, User::query()->findOrFail($ticket->assigned_to))) {
            $this->notifier->send($ticket->assigned_to, NotificationKind::TicketReply,
                __('New reply on ticket #:id', ['id' => $ticket->id]),
                $subject,
                route('support.show', $ticket));
        }
    }
}
