<?php

use App\Models\Auction;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

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
