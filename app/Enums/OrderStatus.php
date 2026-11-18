<?php

namespace App\Enums;

enum OrderStatus: string
{
    case InEscrow = 'in_escrow';
    case Shipped = 'shipped';
    case Completed = 'completed';
    case Disputed = 'disputed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::InEscrow => __('Paid, awaiting shipment'),
            self::Shipped => __('Shipped'),
            self::Completed => __('Completed'),
            self::Disputed => __('In dispute'),
            self::Refunded => __('Refunded'),
            self::PartiallyRefunded => __('Partially refunded'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /** Filament colour name; the site maps it to a badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::InEscrow => 'warning',
            self::Shipped => 'info',
            self::Completed => 'success',
            self::Disputed => 'danger',
            self::Refunded, self::PartiallyRefunded, self::Cancelled => 'gray',
        };
    }

    /** Nothing more happens to the order or its money. */
    public function isFinal(): bool
    {
        return $this->transitions() === [];
    }

    /** Money for this order is still held in escrow. */
    public function holdsEscrow(): bool
    {
        return in_array($this, [self::InEscrow, self::Shipped, self::Disputed], true);
    }

    /** @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::InEscrow => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Completed, self::Disputed],
            // Back to shipped when the buyer withdraws the dispute.
            self::Disputed => [self::Shipped, self::Completed, self::Refunded, self::PartiallyRefunded],
            self::Completed, self::Refunded, self::PartiallyRefunded, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->transitions(), true);
    }
}
