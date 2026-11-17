<?php

namespace App\Services\Realtime;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * How many signed-in people have an auction page open: the members of its
 * presence channel, asked of the websocket server (Reverb and Pusher answer
 * the same API). Signed-in pages count their channel themselves; this is
 * for guests, who cannot join a presence channel. Null when unknown.
 */
final class ViewerCount
{
    public static function channel(int $auctionId): string
    {
        return "auction.{$auctionId}.viewers";
    }

    public function of(int $auctionId): ?int
    {
        $broadcaster = Broadcast::connection();

        if (! $broadcaster instanceof PusherBroadcaster) {
            return null;
        }

        // A failure is remembered too (as -1), so a server that is down is not asked on every page view.
        $count = Cache::remember("auction.{$auctionId}.viewers", (int) config('auction.realtime.viewer_count_seconds'), function () use ($broadcaster, $auctionId): int {
            try {
                $info = $broadcaster->getPusher()->getChannelInfo('presence-'.self::channel($auctionId), ['info' => 'user_count']);

                return isset($info->user_count) ? (int) $info->user_count : 0;
            } catch (Throwable) {
                return -1;
            }
        });

        return $count >= 0 ? $count : null;
    }
}
