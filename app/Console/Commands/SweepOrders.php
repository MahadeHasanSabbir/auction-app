<?php

namespace App\Console\Commands;

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\CompleteOrder;
use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Console\Command;
use Throwable;

/**
 * Cancels and refunds orders not shipped by their ship-by date, and releases
 * shipped orders whose auto-release date has passed. Each action re-checks
 * under the row lock, so the command is safe to run as often as you like.
 */
class SweepOrders extends Command
{
    protected $signature = 'orders:sweep';

    protected $description = 'Cancel orders past their ship-by date and release shipped orders past their auto-release date';

    public function handle(CancelOrder $cancel, CompleteOrder $complete): int
    {
        $counts = ['cancelled' => 0, 'released' => 0, 'failed' => 0];

        $run = function (string $key, callable $step) use (&$counts): void {
            try {
                if ($step()) {
                    $counts[$key]++;
                }
            } catch (Throwable $e) {
                // One order's problem must not stop the others.
                report($e);
                $counts['failed']++;
            }
        };

        Order::query()->where('status', OrderStatus::InEscrow)->where('ship_by_at', '<=', now())
            ->lazyById(100)
            ->each(fn (Order $order) => $run('cancelled', fn () => $cancel->overdue($order)));

        Order::query()->where('status', OrderStatus::Shipped)->where('auto_release_at', '<=', now())
            ->lazyById(100)
            ->each(fn (Order $order) => $run('released', fn () => $complete->autoRelease($order)));

        $this->info(collect($counts)->map(fn (int $n, string $what) => "{$n} {$what}")->implode(', '));

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
