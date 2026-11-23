<?php

namespace App\Actions\Support;

use App\Actions\Messaging\Concerns\PostsMessages;
use App\Enums\ConversationStatus;
use App\Enums\ConversationType;
use App\Enums\NotificationKind;
use App\Filament\Admin\Resources\Tickets\TicketResource;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Opens a support ticket: for a signed-in user, from their account; for a
 * visitor, from the public contact form (a name and an email address, no
 * account). Staff answer it from the admin panel.
 */
final class OpenTicket
{
    use PostsMessages;

    public function __construct(private readonly Notifier $notifier) {}

    /**
     * @param  array<string, mixed>  $input  subject, body and, without a user, name and email
     */
    public function handle(?User $user, array $input): Conversation
    {
        $rules = [
            'subject' => ['required', 'string', 'min:3', 'max:120'],
            'body' => ['required', 'string', 'min:10', 'max:'.(int) config('auction.messaging.ticket_max')],
        ];

        if ($user === null) {
            $rules['name'] = ['required', 'string', 'min:2', 'max:120'];
            $rules['email'] = ['required', 'email:rfc', 'max:190'];
        }

        $data = Validator::make($input, $rules)->validate();

        $key = 'ticket:'.($user !== null ? 'user:'.$user->id : 'email:'.mb_strtolower((string) $data['email']));

        if (RateLimiter::tooManyAttempts($key, (int) config('auction.messaging.tickets_per_hour'))) {
            throw ValidationException::withMessages(['subject' => __('You have sent several messages recently. Please wait a while before writing again.')]);
        }

        // Visitors could rotate made-up addresses; the IP caps them as well
        // (every ticket mails the staff).
        $ipKey = 'ticket:ip:'.request()->ip();

        if ($user === null && RateLimiter::tooManyAttempts($ipKey, (int) config('auction.messaging.tickets_per_hour'))) {
            throw ValidationException::withMessages(['subject' => __('You have sent several messages recently. Please wait a while before writing again.')]);
        }

        RateLimiter::hit($key, 3600);

        if ($user === null) {
            RateLimiter::hit($ipKey, 3600);
        }

        $ticket = DB::transaction(function () use ($user, $data): Conversation {
            $ticket = new Conversation;
            $ticket->forceFill([
                'type' => ConversationType::Support,
                'subject' => trim((string) $data['subject']),
                'status' => ConversationStatus::Open,
                'opened_by' => $user?->id,
                'guest_name' => $user === null ? trim((string) $data['name']) : null,
                'guest_email' => $user === null ? mb_strtolower(trim((string) $data['email'])) : null,
            ])->save();

            $this->append($ticket, $user, trim((string) $data['body']));

            return $ticket;
        });

        $this->notifier->send($this->notifier->platformStaff(), NotificationKind::NewTicket,
            __('New ticket #:id: :subject', ['id' => $ticket->id, 'subject' => $ticket->subject]),
            __('From :name.', ['name' => $user->name ?? $ticket->guest_name]),
            TicketResource::getUrl('view', ['record' => $ticket], panel: 'admin'));

        return $ticket;
    }
}
