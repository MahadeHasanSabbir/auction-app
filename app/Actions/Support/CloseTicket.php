<?php

namespace App\Actions\Support;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Closes a ticket: the requester when it is solved, staff when it is dealt with. Replying reopens it. */
final class CloseTicket
{
    public function handle(User $user, Conversation $ticket): Conversation
    {
        Gate::forUser($user)->authorize('close', $ticket);

        return DB::transaction(function () use ($ticket): Conversation {
            $locked = Conversation::query()->lockForUpdate()->findOrFail($ticket->id);

            if ($locked->status === ConversationStatus::Open) {
                $locked->forceFill(['status' => ConversationStatus::Closed, 'closed_at' => now()])->save();
            }

            return $ticket->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
