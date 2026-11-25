<?php

namespace App\Actions\Bidding;

use App\Support\Money;

/**
 * What a bid did, from the bidder's point of view. Stored with the
 * idempotency key, so a repeated submission gets the same answer.
 */
final readonly class BidResult
{
    public function __construct(
        public bool $leading,
        public Money $price,
        public Money $nextMinimum,
        public bool $extended,
    ) {}

    public function message(): string
    {
        return $this->leading
            ? __('You are the highest bidder at :price.', ['price' => $this->price->format()])
            : __('Another bidder’s maximum is higher. The price is now :price.', ['price' => $this->price->format()]);
    }

    /** @return array{leading: bool, price: int, next_minimum: int, extended: bool} */
    public function toArray(): array
    {
        return [
            'leading' => $this->leading,
            'price' => $this->price->amount,
            'next_minimum' => $this->nextMinimum->amount,
            'extended' => $this->extended,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            leading: (bool) ($data['leading'] ?? false),
            price: Money::minor((int) ($data['price'] ?? 0)),
            nextMinimum: Money::minor((int) ($data['next_minimum'] ?? 0)),
            extended: (bool) ($data['extended'] ?? false),
        );
    }
}
