<?php

namespace App\Enums;

/**
 * What a notification is about. Users switch each category on or off per
 * channel (bell and email) on their notification settings page.
 */
enum NotificationCategory: string
{
    case Bidding = 'bidding';
    case Orders = 'orders';
    case Money = 'money';
    case Activity = 'activity';
    case Listings = 'listings';
    case Support = 'support';
    case Account = 'account';
    case Searches = 'searches';

    public function label(): string
    {
        return match ($this) {
            self::Bidding => __('Bidding'),
            self::Orders => __('Orders and delivery'),
            self::Money => __('Payments and withdrawals'),
            self::Activity => __('Messages and reviews'),
            self::Listings => __('Your auctions'),
            self::Support => __('Support'),
            self::Account => __('Account verification'),
            self::Searches => __('Saved searches'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Bidding => __('When another bidder takes the lead.'),
            self::Orders => __('Wins, new orders, shipping, delivery, cancellations and disputes.'),
            self::Money => __('Withdrawals that were paid, rejected or approved, and bonus credit.'),
            self::Activity => __('New chat messages, delivery addresses, reviews and replies.'),
            self::Listings => __('Auctions approved, rejected or ended without a sale.'),
            self::Support => __('Replies to your support tickets.'),
            self::Account => __('Documents asked for, approved or not accepted.'),
            self::Searches => __('New auctions that match a search you saved.'),
        };
    }

    /** Whether this category is emailed until the user says otherwise. The bell is always on by default. */
    public function mailByDefault(): bool
    {
        return match ($this) {
            self::Orders, self::Money, self::Support, self::Account, self::Searches => true,
            self::Bidding, self::Activity, self::Listings => false,
        };
    }
}
