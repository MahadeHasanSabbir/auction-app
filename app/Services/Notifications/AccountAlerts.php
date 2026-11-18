<?php

namespace App\Services\Notifications;

use App\Enums\NotificationKind;
use App\Filament\Seller\Pages\CompanyWallet;
use App\Filament\Seller\Resources\Auctions\AuctionResource;
use App\Models\Auction;
use App\Models\Company;
use App\Models\User;
use App\Models\WithdrawalRequest;

/** Notifications about bidding, withdrawals and the seller's auctions (orders have OrderAlerts). */
class AccountAlerts
{
    public function __construct(private readonly Notifier $notifier) {}

    /** Another bidder took the lead. */
    public function outbid(int $userId, Auction $auction): void
    {
        $this->notifier->send($userId, NotificationKind::Outbid,
            __('You have been outbid'),
            __('Someone took the lead on “:title”. The price is now :price.', ['title' => $auction->title, 'price' => $auction->current_price->format()]),
            route('auctions.show', $auction));
    }

    /** A withdrawal moved on: approved, paid or rejected. */
    public function withdrawal(WithdrawalRequest $withdrawal, NotificationKind $kind, string $body): void
    {
        $requester = User::query()->find($withdrawal->requested_by);

        if ($requester === null) {
            return;
        }

        $amount = $withdrawal->amount->format();

        $this->notifier->send($requester, $kind,
            match ($kind) {
                NotificationKind::WithdrawalApproved => __('Withdrawal of :amount approved', ['amount' => $amount]),
                NotificationKind::WithdrawalPaid => __('Withdrawal of :amount paid', ['amount' => $amount]),
                default => __('Withdrawal of :amount rejected', ['amount' => $amount]),
            },
            $body,
            $requester->isSeller() && $requester->company_id !== null
                ? CompanyWallet::getUrl(panel: 'seller', tenant: Company::query()->findOrFail($requester->company_id))
                : route('wallet'));
    }

    /** Staff approved or rejected an auction, or it ended without a sale. */
    public function listing(Auction $auction, NotificationKind $kind, string $body): void
    {
        $this->notifier->send($this->notifier->team($auction->company_id), $kind,
            match ($kind) {
                NotificationKind::AuctionApproved => __('“:title” was approved', ['title' => $auction->title]),
                NotificationKind::AuctionRejected => __('“:title” needs changes', ['title' => $auction->title]),
                default => __('“:title” ended without a sale', ['title' => $auction->title]),
            },
            $body,
            AuctionResource::getUrl('view', ['record' => $auction], panel: 'seller', tenant: Company::query()->findOrFail($auction->company_id)));
    }
}
