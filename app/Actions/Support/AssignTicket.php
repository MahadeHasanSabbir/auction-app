<?php

namespace App\Actions\Support;

use App\Enums\NotificationKind;
use App\Filament\Admin\Resources\Tickets\TicketResource;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Staff take a ticket, hand it to a colleague, or put it back in the queue (null). */
final class AssignTicket
{
    public function handle(User $staff, Conversation $ticket, ?User $assignee): Conversation
    {
        Gate::forUser($staff)->authorize('assign', $ticket);

        if ($assignee !== null && (! $assignee->is_active || ! $assignee->isPlatform())) {
            throw ValidationException::withMessages(['assignee' => __('Only platform staff can take a ticket.')]);
        }

        $ticket = DB::transaction(function () use ($ticket, $assignee): Conversation {
            $locked = Conversation::query()->lockForUpdate()->findOrFail($ticket->id);
            $locked->forceFill(['assigned_to' => $assignee?->id])->save();

            return $ticket->setRawAttributes($locked->getAttributes(), true);
        });

        // Someone handed it to a colleague: tell the colleague.
        if ($assignee !== null && ! $assignee->is($staff)) {
            app(Notifier::class)->send($assignee, NotificationKind::NewTicket,
                __(':name gave you ticket #:id', ['name' => $staff->name, 'id' => $ticket->id]),
                (string) $ticket->subject,
                TicketResource::getUrl('view', ['record' => $ticket], panel: 'admin'));
        }

        return $ticket;
    }
}
