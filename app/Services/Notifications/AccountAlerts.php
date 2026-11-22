<?php

namespace App\Services\Notifications;

use App\Enums\KycStatus;
use App\Enums\NotificationKind;
use App\Filament\Admin\Resources\Kyc\KycRequestResource;
use App\Filament\Seller\Pages\CompanyWallet;
use App\Filament\Seller\Pages\Verification;
use App\Filament\Seller\Resources\Auctions\AuctionResource;
use App\Models\Auction;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\KycRequest;
use App\Models\SavedSearch;
use App\Models\Strike;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Support\Money;

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

    /** A newly approved auction matches a buyer's saved search. */
    public function savedSearch(SavedSearch $search, Auction $auction): void
    {
        $this->notifier->send($search->user_id, NotificationKind::SavedSearchMatch,
            __('New auction for “:search”', ['search' => $search->name]),
            __('“:title” starts at :price.', ['title' => $auction->title, 'price' => $auction->current_price->format()]),
            $auction->url());
    }

    /** Bonus credit was added to a buyer's wallet. */
    public function bonus(int $userId, Money $amount, string $body): void
    {
        $this->notifier->send($userId, NotificationKind::BonusCredited,
            __(':amount bonus credit added', ['amount' => $amount->format()]),
            $body,
            route('wallet'));
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

    /** Staff asked a buyer or a company for identity documents. */
    public function kycRequested(User|Company $subject, ?string $note): void
    {
        $this->notifier->send($this->kycRecipients($subject), NotificationKind::KycRequested,
            __('Please verify your identity'),
            $note ?? __('Upload your documents to keep bidding, selling and withdrawing.'),
            $this->kycUrl($subject));
    }

    /** Documents arrived for review: every platform account hears of it. */
    public function kycSubmitted(KycRequest $request, User|Company $subject): void
    {
        $this->notifier->send($this->notifier->platformStaff(), NotificationKind::KycSubmitted,
            __('Documents to check: :name', ['name' => $subject->name]),
            $subject instanceof Company ? __('A company sent its verification documents.') : __('A buyer sent their verification documents.'),
            KycRequestResource::getUrl('view', ['record' => $request], panel: 'admin'));
    }

    /** Staff approved or rejected the documents. */
    public function kycReviewed(KycRequest $request, User|Company $subject): void
    {
        $approved = $request->status === KycStatus::Approved;

        $this->notifier->send($this->kycRecipients($subject), $approved ? NotificationKind::KycApproved : NotificationKind::KycRejected,
            $approved ? __('You are verified') : __('Your documents were not accepted'),
            $approved
                ? __('Your documents were approved. Everything is open to you now.')
                : __('Reason: :reason Please upload them again.', ['reason' => $request->rejection_reason]),
            $this->kycUrl($subject));
    }

    /** @return iterable<User>|User */
    private function kycRecipients(User|Company $subject): iterable|User
    {
        return $subject instanceof Company ? $this->notifier->team($subject) : $subject;
    }

    private function kycUrl(User|Company $subject): string
    {
        return $subject instanceof Company
            ? Verification::getUrl(panel: 'seller', tenant: $subject)
            : route('verification');
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

    /** A strike was given: the buyer hears why, and how close the limit is. */
    public function strike(User $buyer, Strike $strike, int $active, int $limit): void
    {
        $this->notifier->send($buyer, NotificationKind::StrikeIssued,
            __('A strike was added to your account'),
            __(':reason You have :count of :limit strikes; at :limit the account is suspended.', ['reason' => rtrim($strike->reason, '.').'.', 'count' => $active, 'limit' => $limit]),
            route('dashboard'));
    }

    /** Staff fined a buyer or a company. */
    public function fine(Wallet $wallet, Invoice $invoice, string $reason): void
    {
        $company = $wallet->company_id === null ? null : Company::query()->find($wallet->company_id);

        $this->notifier->send($company !== null ? $this->notifier->team($company) : $wallet->user_id, NotificationKind::FineIssued,
            __('Fine of :amount', ['amount' => $invoice->total->format()]),
            __('Taken from your wallet: :reason', ['reason' => $reason]),
            $company !== null ? CompanyWallet::getUrl(panel: 'seller', tenant: $company) : route('wallet'));
    }
}
