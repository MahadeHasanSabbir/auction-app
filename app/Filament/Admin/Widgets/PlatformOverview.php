<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\AuctionStatus;
use App\Enums\CompanyStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\SystemAccount;
use App\Enums\WithdrawalStatus;
use App\Filament\Admin\Resources\Auctions\AuctionResource;
use App\Filament\Admin\Resources\Companies\CompanyResource;
use App\Models\Auction;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** What needs the platform's attention, and how plans are doing. */
class PlatformOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected static bool $isLazy = false;

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $pending = Company::query()->where('status', CompanyStatus::Pending)->count();
        $owner = auth()->user()?->isSystemOwner() ?? false;
        $revenue = Wallet::query()->where('system_account', SystemAccount::SubscriptionRevenue)->first();

        $toReview = Auction::query()->where('status', AuctionStatus::PendingReview)->count();

        return [
            Stat::make(__('Auctions to review'), (string) $toReview)
                ->description($toReview > 0 ? __('Sellers are waiting') : __('All caught up'))
                ->descriptionColor($toReview > 0 ? 'warning' : 'success')
                ->icon(Heroicon::OutlinedMegaphone)
                ->url(AuctionResource::getUrl('index')),
            Stat::make(__('Companies to verify'), (string) $pending)
                ->description($pending > 0 ? __('Waiting for review') : __('All caught up'))
                ->descriptionColor($pending > 0 ? 'warning' : 'success')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->url($owner ? CompanyResource::getUrl('index', ['filters' => ['status' => ['value' => CompanyStatus::Pending->value]]]) : null),
            Stat::make(__('Companies selling'), (string) Subscription::query()->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trialing])->count())
                ->description(__(':count past due', ['count' => Subscription::query()->where('status', SubscriptionStatus::PastDue)->count()]))
                ->icon(Heroicon::OutlinedBuildingStorefront),
            Stat::make(__('Plan revenue'), $revenue?->balance->format() ?? '—')
                ->description(__('All time'))
                ->icon(Heroicon::OutlinedChartBar),
            Stat::make(__('Payouts to send'), (string) WithdrawalRequest::query()->whereIn('status', [WithdrawalStatus::Pending, WithdrawalStatus::Approved])->count())
                ->description(__('Pending or approved'))
                ->icon(Heroicon::OutlinedArrowUpTray),
        ];
    }
}
