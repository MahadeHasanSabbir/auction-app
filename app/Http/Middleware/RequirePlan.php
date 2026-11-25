<?php

namespace App\Http\Middleware;

use App\Enums\SubscriptionStatus;
use App\Filament\Seller\Pages\Billing;
use App\Filament\Seller\Pages\Verification;
use App\Models\Company;
use App\Models\Subscription;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A new company lands on the billing page until it has chosen a plan (and,
 * for a paid plan, paid its first invoice). Only page loads are redirected,
 * so Livewire's background requests, the billing page itself and the
 * verification page keep working.
 * A company whose plan ended can still reach its panel: running auctions and
 * orders finish.
 */
class RequirePlan
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = Filament::getTenant();

        if (! $company instanceof Company || ! $request->isMethod('GET') || $request->routeIs(Billing::getRouteName(Filament::getPanel('seller')), Verification::getRouteName(Filament::getPanel('seller')))) {
            return $next($request);
        }

        $status = Subscription::query()->where('company_id', $company->id)->value('status');
        $status = $status === null ? null : SubscriptionStatus::from($status instanceof SubscriptionStatus ? $status->value : (string) $status);

        if ($status === null || $status === SubscriptionStatus::Incomplete) {
            return redirect(Billing::getUrl(panel: 'seller', tenant: $company));
        }

        return $next($request);
    }
}
