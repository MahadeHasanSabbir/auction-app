<?php

namespace App\Http\Resources\V1;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order. The buyer sees what they paid; the selling team also sees its
 * commission and payout. Load auction, company, buyer and (for the detail)
 * shipment, events, review and latestDispute first.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $seller = $user !== null && $user->company_id !== null && $user->company_id === $this->company_id;

        return [
            'number' => $this->number,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'discount' => $this->discount,
            'paid' => $this->paid(),
            'refunded_amount' => $this->refunded_amount,
            'commission' => $this->when($seller, fn () => $this->commission),
            'seller_payout' => $this->when($seller, fn () => $this->seller_payout),
            'auction' => $this->whenLoaded('auction', fn () => ['id' => $this->auction->id, 'title' => $this->auction->title]),
            'seller' => $this->whenLoaded('company', fn () => ['id' => $this->company->id, 'name' => $this->company->name, 'slug' => $this->company->slug]),
            'buyer' => $this->when($seller, fn () => $this->buyer?->maskedName()),
            'delivery' => [
                'name' => $this->shipping_name,
                'phone' => $this->shipping_phone,
                'address' => $this->shipping_address,
            ],
            'ship_by_at' => $this->ship_by_at?->toIso8601ZuluString(),
            'shipped_at' => $this->shipped_at?->toIso8601ZuluString(),
            'auto_release_at' => $this->auto_release_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'cancelled_at' => $this->cancelled_at?->toIso8601ZuluString(),
            'cancellation_reason' => $this->cancellation_reason,
            'shipment' => $this->whenLoaded('shipment', fn () => $this->shipment === null ? null : [
                'courier' => $this->shipment->courier,
                'tracking_number' => $this->shipment->tracking_number,
                'tracking_url' => $this->shipment->tracking_url,
                'note' => $this->shipment->note,
            ]),
            'dispute' => $this->whenLoaded('latestDispute', fn () => $this->latestDispute === null ? null : [
                'status' => $this->latestDispute->status->value,
                'reason' => $this->latestDispute->reason->value,
            ]),
            'review' => $this->whenLoaded('review', fn () => $this->review === null ? null : [
                'rating' => $this->review->rating,
                'comment' => $this->review->comment,
                'reply' => $this->review->reply,
            ]),
            'timeline' => $this->whenLoaded('events', fn () => $this->events->sortByDesc('id')->values()->map(fn ($event) => [
                'event' => $event->event,
                'description' => $event->description,
                'at' => $event->created_at?->toIso8601ZuluString(),
            ])->all()),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
