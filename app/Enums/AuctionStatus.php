<?php

namespace App\Enums;

enum AuctionStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Rejected = 'rejected';
    case Scheduled = 'scheduled';
    case Live = 'live';
    case Sold = 'sold';
    case Unsold = 'unsold';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::PendingReview => __('Awaiting review'),
            self::Rejected => __('Rejected'),
            self::Scheduled => __('Scheduled'),
            self::Live => __('Live'),
            self::Sold => __('Sold'),
            self::Unsold => __('Unsold'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /** Badge colour in the panels. */
    public function color(): string
    {
        return match ($this) {
            self::Draft, self::Cancelled => 'gray',
            self::PendingReview => 'warning',
            self::Rejected => 'danger',
            self::Scheduled => 'info',
            self::Live => 'success',
            self::Sold => 'primary',
            self::Unsold => 'gray',
        };
    }

    /** No further transition is possible. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Sold, self::Unsold, self::Cancelled], true);
    }

    /** The seller may still change it (a rejected one goes back to draft when edited). */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    /**
     * Sent for review or approved, and not finished: it counts against the
     * plan's active-auction allowance and freezes its product's details,
     * since the platform reviewed what it saw.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::PendingReview, self::Scheduled, self::Live];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    /** Visible to the public on listing pages. */
    public function isPublic(): bool
    {
        return in_array($this, [self::Scheduled, self::Live, self::Sold, self::Unsold], true);
    }

    /**
     * Allowed next states. Every status change in the application is checked
     * against this map, so an impossible transition fails loudly.
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingReview, self::Cancelled],
            // Back to draft when the seller withdraws it from review.
            self::PendingReview => [self::Draft, self::Scheduled, self::Rejected, self::Cancelled],
            self::Rejected => [self::Draft, self::Cancelled],
            self::Scheduled => [self::Live, self::Cancelled],
            self::Live => [self::Sold, self::Unsold, self::Cancelled],
            self::Sold, self::Unsold, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->transitions(), true);
    }
}
