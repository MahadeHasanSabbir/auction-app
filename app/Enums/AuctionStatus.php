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

    /** No further transition is possible. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Sold, self::Unsold, self::Cancelled], true);
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
            self::PendingReview => [self::Scheduled, self::Rejected, self::Cancelled],
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
