<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\ChangesOrders;
use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use App\Enums\OrderStatus;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Platform staff decide an open dispute and settle the escrow: a full refund
 * to the buyer, a partial refund (the rest goes to the company with a
 * proportionally smaller commission), or release to the company. Logged to
 * the `money` activity log with the note.
 */
final class ResolveDispute
{
    use ChangesOrders;

    public function __construct(private readonly SettleEscrow $settle) {}

    public function handle(User $staff, Order $order, DisputeResolution $resolution, string $note, ?string $refund = null): Dispute
    {
        Gate::forUser($staff)->authorize('resolve', $order);

        $note = trim($note);

        if (mb_strlen($note) < 3 || mb_strlen($note) > 2000) {
            throw ValidationException::withMessages(['note' => __('Explain the decision to both sides.')]);
        }

        return DB::transaction(function () use ($staff, $order, $resolution, $note, $refund): Dispute {
            $locked = $this->lockOrder($order, OrderStatus::Disputed);
            $dispute = Dispute::query()->where('order_id', $locked->id)->where('status', DisputeStatus::Open)->lockForUpdate()->sole();

            $amount = match ($resolution) {
                DisputeResolution::RefundFull => $locked->amount,
                DisputeResolution::ReleaseToSeller => Money::zero($locked->amount->currency),
                DisputeResolution::RefundPartial => $this->partial($locked, $refund),
            };

            $this->settle->handle($locked, $amount, $staff, "Order {$locked->number} dispute resolved: {$resolution->value}");

            $next = match ($resolution) {
                DisputeResolution::RefundFull => OrderStatus::Refunded,
                DisputeResolution::RefundPartial => OrderStatus::PartiallyRefunded,
                DisputeResolution::ReleaseToSeller => OrderStatus::Completed,
            };
            $from = $this->moveTo($locked, $next);

            if ($next === OrderStatus::Completed) {
                $locked->completed_at = now();
            }

            $locked->save();

            $dispute->forceFill([
                'status' => DisputeStatus::Resolved,
                'resolution' => $resolution,
                'refund_amount' => $amount,
                'resolved_by' => $staff->id,
                'resolved_at' => now(),
                'resolution_note' => $note,
            ])->save();

            $this->record($locked, 'dispute_resolved', __('Dispute resolved: :resolution. :note', [
                'resolution' => $resolution->label(),
                'note' => $note,
            ]), $staff, $from, $next, ['refund' => $amount->amount]);

            activity('money')
                ->causedBy($staff)
                ->performedOn($dispute)
                ->event('resolved')
                ->withProperties([
                    'order' => $locked->number,
                    'resolution' => $resolution->value,
                    'refund' => $amount->amount,
                    'currency' => $amount->currency,
                    'note' => $note,
                ])
                ->log("Dispute {$dispute->id} on order {$locked->number} resolved");

            $this->syncFrom($order, $locked);

            return $dispute;
        }, attempts: 3);
    }

    private function partial(Order $order, ?string $refund): Money
    {
        try {
            $amount = Money::parse((string) $refund, $order->amount->currency);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['refund' => __('Enter the amount to refund.')]);
        }

        if (! $amount->isPositive() || ! $amount->lessThan($order->amount)) {
            throw ValidationException::withMessages(['refund' => __('A partial refund is more than nothing and less than :amount.', ['amount' => $order->amount->format()])]);
        }

        return $amount;
    }
}
