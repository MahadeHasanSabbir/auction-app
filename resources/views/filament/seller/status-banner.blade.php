@php
    use App\Enums\CompanyStatus;
    use App\Enums\SubscriptionStatus;
    use App\Filament\Seller\Pages\Billing;
    use App\Models\Company;
    use App\Models\Subscription;

    $company = filament()->getTenant();
    $banner = null;

    // The billing page explains the plan's state itself.
    if ($company instanceof Company && ! request()->routeIs(Billing::getRouteName(filament()->getPanel('seller')))) {
        $subscription = Subscription::query()->where('company_id', $company->id)->first();
        $tz = auth()->user()?->displayTimezone();
        $billing = Billing::getUrl(panel: 'seller', tenant: $company);

        $banner = match (true) {
            $company->isSuspended() => ['danger', 'heroicon-o-no-symbol', __('This company is suspended'), $company->suspension_reason ?? __('It cannot create listings or add people. Contact the platform for details.'), null],
            $subscription?->status === SubscriptionStatus::PastDue => ['warning', 'heroicon-o-exclamation-triangle', __('Your plan payment is overdue'), __('Pay the open invoice by :date to keep listing.', ['date' => $subscription->grace_ends_at?->timezone($tz)->format('j M Y') ?? '—']), [$billing, __('Pay now')]],
            $subscription?->status === SubscriptionStatus::Expired => ['danger', 'heroicon-o-x-circle', __('Your plan has ended'), __('You cannot create new listings. Running auctions and orders continue.'), [$billing, __('Choose a plan')]],
            $subscription?->status === SubscriptionStatus::Trialing && $subscription->trial_ends_at !== null && $subscription->trial_ends_at->diffInDays(now(), true) <= 3 => ['info', 'heroicon-o-clock', __('Your trial ends on :date', ['date' => $subscription->trial_ends_at->timezone($tz)->format('j M Y')]), __('Your plan is invoiced then. Pay it from the company wallet or online.'), [$billing, __('See plan')]],
            $company->status === CompanyStatus::Pending => ['info', 'heroicon-o-shield-check', __('Your company is being verified'), __('Set everything up now; you can list as soon as the platform has verified you.'), null],
            default => null,
        };
    }
@endphp

@if ($banner)
    @php
        [$color, $icon, $heading, $description, $link] = $banner;
    @endphp
    <div class="mb-6">
        <x-filament::callout :color="$color" :icon="$icon" :heading="$heading" :description="$description">
            @if ($link)
                <x-slot name="controls">
                    <x-filament::button :href="$link[0]" tag="a" size="sm" :color="$color === 'info' ? 'primary' : $color">{{ $link[1] }}</x-filament::button>
                </x-slot>
            @endif
        </x-filament::callout>
    </div>
@endif
