<?php

namespace App\Events\Account;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something for one user only, on their private channel
 * `App.Models.User.{id}`, sent once the transaction commits. Every page of
 * the site listens to it while the user is signed in (resources/js/live.js).
 */
abstract class UserBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    abstract protected function userId(): int;

    abstract public function broadcastAs(): string;

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->userId());
    }

    public function broadcastQueue(): string
    {
        return (string) config('auction.realtime.queue');
    }
}
