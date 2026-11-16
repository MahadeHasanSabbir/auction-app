<?php

namespace App\Providers;

use App\Models\Auction;
use App\Models\Company;
use App\Models\CompanyAgreement;
use App\Models\Deposit;
use App\Models\Dispute;
use App\Models\FeeRule;
use App\Models\KycRequest;
use App\Models\Notice;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $production = $this->app->isProduction();

        // Outside production an N+1 query throws, so it is caught by the test
        // suite instead of by a slow page.
        Model::preventLazyLoading(! $production);

        // Polymorphic columns (ledger references, notifications, the activity
        // log) store these short names instead of class names, so renaming or
        // moving a model does not orphan rows. A model missing here throws.
        Relation::enforceMorphMap([
            'user' => User::class,
            'company' => Company::class,
            'auction' => Auction::class,
            'product' => Product::class,
            'order' => Order::class,
            'deposit' => Deposit::class,
            'withdrawal_request' => WithdrawalRequest::class,
            'subscription' => Subscription::class,
            'subscription_invoice' => SubscriptionInvoice::class,
            'company_agreement' => CompanyAgreement::class,
            'fee_rule' => FeeRule::class,
            'plan' => Plan::class,
            'dispute' => Dispute::class,
            'kyc_request' => KycRequest::class,
            'notice' => Notice::class,
            'setting' => Setting::class,
        ]);

        // Dates are immutable everywhere, so passing one around cannot change it.
        Date::use(CarbonImmutable::class);

        // Refuse `migrate:fresh`, `db:wipe` and friends against production data.
        DB::prohibitDestructiveCommands($production);

        if ($production) {
            URL::forceScheme('https');
        }

        Password::defaults(fn () => $production
            ? Password::min(10)->letters()->mixedCase()->numbers()->uncompromised()
            : Password::min(8));
    }
}
