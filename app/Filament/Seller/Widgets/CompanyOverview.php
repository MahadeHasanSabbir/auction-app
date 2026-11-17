<?php

namespace App\Filament\Seller\Widgets;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Seller\Pages\Billing;
use App\Filament\Seller\Pages\CompanyWallet;
use App\Filament\Seller\Pages\Team;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Wallet;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** The seller dashboard at a glance: money, plan and team. */
class CompanyOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $company = Filament::getTenant();

        if (! $company instanceof Company) {
            return [];
        }

        $wallet = Wallet::query()->where('company_id', $company->id)->first();
        $subscription = Subscription::query()->with('plan')->where('company_id', $company->id)->first();
        $openInvoice = SubscriptionInvoice::query()->where('company_id', $company->id)->where('status', InvoiceStatus::Open)->latest('id')->first();
        $used = $company->seatsUsed();
        $seats = $subscription?->maxUsers() ?? 0;
        $tz = auth()->user()?->displayTimezone();

        return [
            Stat::make(__('Available balance'), $wallet?->available()->format() ?? '—')
                ->description($wallet !== null && $wallet->held->isPositive() ? __(':amount on hold', ['amount' => $wallet->held->format()]) : __('Sale proceeds land here'))
                ->icon(Heroicon::OutlinedBanknotes)
                ->url(CompanyWallet::getUrl()),
            Stat::make(__('Plan'), $subscription?->plan->name ?? __('None yet'))
                ->description(match (true) {
                    $openInvoice !== null => __('Invoice :number is due', ['number' => $openInvoice->number]),
                    $subscription === null => __('Choose a plan to start'),
                    $subscription->status === SubscriptionStatus::Trialing => __('Trial until :date', ['date' => $subscription->trial_ends_at?->timezone($tz)->format('j M')]),
                    $subscription->status === SubscriptionStatus::Active => __('Renews :date', ['date' => $subscription->current_period_end?->timezone($tz)->format('j M Y')]),
                    default => $subscription->status->label(),
                })
                ->descriptionColor($openInvoice !== null || ! ($subscription?->allowsSelling() ?? false) ? 'warning' : 'gray')
                ->icon(Heroicon::OutlinedCreditCard)
                ->url(Billing::getUrl()),
            Stat::make(__('Team'), $seats > 0 ? "{$used} / {$seats}" : (string) $used)
                ->description($seats > 0 && $used >= $seats ? __('All seats taken') : __('Seats in use'))
                ->descriptionColor($seats > 0 && $used >= $seats ? 'warning' : 'gray')
                ->icon(Heroicon::OutlinedUserGroup)
                ->url(Team::getUrl()),
        ];
    }
}
