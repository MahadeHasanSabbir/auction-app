<?php

namespace App\Providers;

use App\Models\Auction;
use App\Models\BlacklistEntry;
use App\Models\Company;
use App\Models\CompanyAgreement;
use App\Models\Deposit;
use App\Models\Dispute;
use App\Models\FeeRule;
use App\Models\FraudFlag;
use App\Models\Invoice;
use App\Models\KycRequest;
use App\Models\LedgerTransaction;
use App\Models\Notice;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Strike;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Policies\ActivityPolicy;
use App\Services\Ledger\Ledger;
use App\Services\Settings\Settings;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Remembers the platform accounts it has looked up; scoped so a queue
        // worker starts every job with a fresh copy.
        $this->app->scoped(Ledger::class);
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
            'invoice' => Invoice::class,
            'ledger_transaction' => LedgerTransaction::class,
            'strike' => Strike::class,
            'fraud_flag' => FraudFlag::class,
            'blacklist_entry' => BlacklistEntry::class,
        ]);

        // Values the system owner changed in the admin panel override the
        // config defaults; a queue worker picks up changes between jobs.
        $this->app->make(Settings::class)->apply();
        Event::listen(Looping::class, fn () => $this->app->make(Settings::class)->apply());

        Gate::policy(Activity::class, ActivityPolicy::class);

        // Report pages: the marketplace figures for every platform account, the
        // platform's revenue for the system owner only.
        Gate::define('viewReports', fn (User $user) => $user->is_active && $user->isPlatform());
        Gate::define('viewRevenue', fn (User $user) => $user->is_active && $user->isSystemOwner());

        // Dates are immutable everywhere, so passing one around cannot change it.
        Date::use(CarbonImmutable::class);

        // Panel tables and infolists show times in the marketplace's display
        // timezone; they are stored in UTC.
        FilamentTimezone::set((string) config('auction.display_timezone'));

        // Refuse `migrate:fresh`, `db:wipe` and friends against production data.
        DB::prohibitDestructiveCommands($production);

        if ($production) {
            URL::forceScheme('https');

            // Debug mode shows visitors the exception, the code around it, the
            // SQL and the request. It is never allowed in production, whatever
            // .env says.
            if (config('app.debug')) {
                config(['app.debug' => false]);
                Log::critical('APP_DEBUG is true in production; it has been turned off. Set APP_DEBUG=false in .env.');
            }
        }

        Password::defaults(fn () => $production
            ? Password::min(10)->letters()->mixedCase()->numbers()->uncompromised()
            : Password::min(8));
    }
}
