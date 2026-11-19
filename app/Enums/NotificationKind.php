<?php

namespace App\Enums;

/** Every notification the platform sends, and the category it belongs to. */
enum NotificationKind: string
{
    case Outbid = 'outbid';
    case AuctionWon = 'auction_won';
    case NewOrder = 'new_order';
    case AddressReceived = 'address_received';
    case OrderShipped = 'order_shipped';
    case OrderCompleted = 'order_completed';
    case OrderCancelled = 'order_cancelled';
    case DisputeOpened = 'dispute_opened';
    case DisputeWithdrawn = 'dispute_withdrawn';
    case DisputeResolved = 'dispute_resolved';
    case ReviewLeft = 'review_left';
    case ReviewReplied = 'review_replied';
    case ChatMessage = 'chat_message';
    case WithdrawalApproved = 'withdrawal_approved';
    case WithdrawalPaid = 'withdrawal_paid';
    case WithdrawalRejected = 'withdrawal_rejected';
    case AuctionApproved = 'auction_approved';
    case AuctionRejected = 'auction_rejected';
    case AuctionUnsold = 'auction_unsold';
    case TicketReply = 'ticket_reply';
    case NewTicket = 'new_ticket';
    case KycRequested = 'kyc_requested';
    case KycSubmitted = 'kyc_submitted';
    case KycApproved = 'kyc_approved';
    case KycRejected = 'kyc_rejected';
    case StrikeIssued = 'strike_issued';
    case FineIssued = 'fine_issued';

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::Outbid => NotificationCategory::Bidding,
            self::AuctionWon, self::NewOrder, self::OrderShipped, self::OrderCompleted, self::OrderCancelled,
            self::DisputeOpened, self::DisputeWithdrawn, self::DisputeResolved => NotificationCategory::Orders,
            self::AddressReceived, self::ReviewLeft, self::ReviewReplied, self::ChatMessage => NotificationCategory::Activity,
            self::WithdrawalApproved, self::WithdrawalPaid, self::WithdrawalRejected, self::FineIssued => NotificationCategory::Money,
            self::AuctionApproved, self::AuctionRejected, self::AuctionUnsold => NotificationCategory::Listings,
            self::TicketReply, self::NewTicket => NotificationCategory::Support,
            self::KycRequested, self::KycSubmitted, self::KycApproved, self::KycRejected, self::StrikeIssued => NotificationCategory::Account,
        };
    }
}
