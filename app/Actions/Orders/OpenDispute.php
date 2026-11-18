<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\ChangesOrders;
use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use App\Enums\OrderStatus;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The buyer reports a problem with a shipped order before it is released.
 * The order is frozen (no confirmation, no auto-release) until the buyer
 * withdraws the dispute or platform staff resolve it.
 */
final class OpenDispute
{
    use ChangesOrders;

    /** @param  array<string, mixed>  $input */
    public function handle(User $buyer, Order $order, array $input): Dispute
    {
        Gate::forUser($buyer)->authorize('dispute', $order);

        /** @var array{reason: string, description: string} $data */
        $data = Validator::make($input, [
            'reason' => ['required', Rule::enum(DisputeReason::class)],
            'description' => ['required', 'string', 'min:20', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($buyer, $order, $data): Dispute {
            $locked = $this->lockOrder($order, OrderStatus::Shipped);

            if ($locked->auto_release_at !== null && now()->greaterThanOrEqualTo($locked->auto_release_at)) {
                throw ValidationException::withMessages(['reason' => __('The time to report a problem with this order has passed.')]);
            }

            $dispute = new Dispute;
            $dispute->forceFill([
                'order_id' => $locked->id,
                'opened_by' => $buyer->id,
                'reason' => $data['reason'],
                'description' => trim($data['description']),
                'status' => DisputeStatus::Open,
            ])->save();

            $from = $this->moveTo($locked, OrderStatus::Disputed);
            $locked->save();

            $this->record($locked, 'disputed', __('The buyer opened a dispute: :reason', ['reason' => DisputeReason::from($data['reason'])->label()]), $buyer, $from, OrderStatus::Disputed);

            $this->syncFrom($order, $locked);

            return $dispute;
        });
    }
}
