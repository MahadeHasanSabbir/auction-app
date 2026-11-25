<?php

namespace App\Actions\Messaging;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** Records that the person has seen everything in a conversation up to now. */
final class MarkConversationRead
{
    public function handle(User $user, Conversation $conversation): void
    {
        Gate::forUser($user)->authorize('view', $conversation);

        $conversation->participants()->syncWithoutDetaching([$user->id => ['last_read_at' => now()]]);
    }
}
