<?php

namespace App\Actions\Messaging\Concerns;

use App\Events\Messaging\MessagePosted;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** The steps every message shares: check the text, store it, tell the open pages. */
trait PostsMessages
{
    /** Trims the text and checks its length. */
    private function cleanBody(string $body, int $max, string $field = 'body'): string
    {
        $body = trim($body);

        if ($body === '' || mb_strlen($body) > $max) {
            throw ValidationException::withMessages([$field => __('Write a message of up to :max characters.', ['max' => number_format($max)])]);
        }

        return $body;
    }

    /** Stops one person flooding a conversation. */
    private function throttle(User $author): void
    {
        $key = 'message:'.$author->id;
        $limit = (int) config('auction.messaging.messages_per_minute');

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw ValidationException::withMessages(['body' => __('You are sending messages too fast. Wait a moment and try again.')]);
        }

        RateLimiter::hit($key, 60);
    }

    /** Adds the message and marks the conversation as read by its author. Runs inside a transaction. */
    private function append(Conversation $conversation, ?User $author, string $body): Message
    {
        $message = new Message;
        $message->forceFill([
            'conversation_id' => $conversation->id,
            'user_id' => $author?->id,
            'body' => $body,
        ])->save();

        $conversation->forceFill(['last_message_at' => now()])->save();

        if ($author !== null) {
            $conversation->participants()->syncWithoutDetaching([$author->id => ['last_read_at' => now()]]);
        }

        MessagePosted::dispatch($conversation->id, $message->id);

        return $message;
    }

    /**
     * Whether this person should be told about a new message: not while an
     * earlier notification of theirs is still unread, so a busy chat rings the
     * bell once. Records that they are being told.
     */
    private function shouldNotify(Conversation $conversation, User $recipient): bool
    {
        $conversation->participants()->syncWithoutDetaching([$recipient->id]);

        $row = DB::table('conversation_participants')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $recipient->id)
            ->first(['notified_at', 'last_read_at']);
        $notifiedAt = $row->notified_at ?? null;
        $readAt = $row->last_read_at ?? null;

        if ($notifiedAt !== null && ($readAt === null || $readAt < $notifiedAt)) {
            return false;
        }

        $conversation->participants()->updateExistingPivot($recipient->id, ['notified_at' => now()]);

        return true;
    }
}
