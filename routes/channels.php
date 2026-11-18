<?php

use App\Models\Auction;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

/*
 * Public channels need no rule here: `auction.{id}` carries an auction's
 * public state (App\Events\Auctions). Private and presence channels are
 * authorised below.
 */

// One user's own events: outbid, won, wallet changes (App\Events\Account).
Broadcast::channel('App.Models.User.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id;
});

// Who is looking at an auction, for the viewer count. Members are known
// only by User::getAuthIdentifierForBroadcasting(), never by id or name.
Broadcast::channel('auction.{auction}.viewers', function (User $user, Auction $auction) {
    // Presence data must be a non-empty array; it says nothing about the user.
    return $auction->status->isPublic() ? ['viewer' => true] : false;
});

// An order chat or a support ticket: the people ConversationPolicy::view allows.
Broadcast::channel('conversation.{conversation}', function (User $user, Conversation $conversation) {
    return Gate::forUser($user)->allows('view', $conversation);
});
