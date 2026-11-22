<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The marketplace values the system owner can change without a deploy.
 * config/auction.php holds the defaults; a row in `settings` overrides one
 * and is laid over the config at boot (and before every queued job), so the
 * code keeps reading config('auction.…') and never asks this class.
 *
 * Only the keys listed in definitions() can be overridden.
 */
final class Settings
{
    private const CACHE_KEY = 'settings.overrides';

    /**
     * The config values as they were before any override, so a removed
     * override falls back to them.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $defaults = null;

    /**
     * Key (under config('auction')) => how it is shown and checked. Kinds:
     * `percent` is stored as basis points, `money` as minor units, `integer` as is.
     *
     * @return array<string, array{group: string, label: string, help: string, kind: 'integer'|'percent'|'money', min: int, max: int}>
     */
    public static function definitions(): array
    {
        return [
            'bidding.biddable_share_bps' => ['group' => 'bidding', 'label' => __('Share of the balance a buyer may bid'), 'help' => __('Of the available balance.'), 'kind' => 'percent', 'min' => 100, 'max' => 10_000],
            'bidding.anti_snipe_window_seconds' => ['group' => 'bidding', 'label' => __('Late-bid window (seconds)'), 'help' => __('A bid this close to the end extends the auction. Applies to auctions approved from now on.'), 'kind' => 'integer', 'min' => 0, 'max' => 3_600],
            'bidding.anti_snipe_extend_seconds' => ['group' => 'bidding', 'label' => __('Extension (seconds)'), 'help' => __('How far a late bid moves the end.'), 'kind' => 'integer', 'min' => 0, 'max' => 3_600],
            'bidding.anti_snipe_max_extensions' => ['group' => 'bidding', 'label' => __('Most extensions per auction'), 'help' => __('0 turns extensions off.'), 'kind' => 'integer', 'min' => 0, 'max' => 500],
            'trust.retract_window_minutes' => ['group' => 'trust', 'label' => __('Minutes to retract a bid'), 'help' => __('After the buyer’s latest bid on the auction. 0 turns retraction off.'), 'kind' => 'integer', 'min' => 0, 'max' => 1_440],
            'trust.retract_closing_minutes' => ['group' => 'trust', 'label' => __('No retraction in the last minutes'), 'help' => __('Before the auction ends.'), 'kind' => 'integer', 'min' => 0, 'max' => 10_080],
            'trust.strike_days' => ['group' => 'trust', 'label' => __('Days a strike counts'), 'help' => '', 'kind' => 'integer', 'min' => 1, 'max' => 3_650],
            'trust.strike_limit' => ['group' => 'trust', 'label' => __('Strikes that suspend an account'), 'help' => '', 'kind' => 'integer', 'min' => 1, 'max' => 20],
            'tax.vat_bps' => ['group' => 'tax', 'label' => __('VAT on platform fees'), 'help' => __('Included in every fee and plan price. 0 turns it off.'), 'kind' => 'percent', 'min' => 0, 'max' => 5_000],
            'orders.ship_by_days' => ['group' => 'orders', 'label' => __('Days to ship'), 'help' => __('Counted from the delivery address; also the buyer’s time to give one.'), 'kind' => 'integer', 'min' => 1, 'max' => 60],
            'orders.auto_release_days' => ['group' => 'orders', 'label' => __('Days until payout'), 'help' => __('After shipping, unless the buyer confirms earlier or disputes.'), 'kind' => 'integer', 'min' => 1, 'max' => 60],
            'orders.review_days' => ['group' => 'orders', 'label' => __('Days to leave a review'), 'help' => __('After the order completes.'), 'kind' => 'integer', 'min' => 1, 'max' => 365],
            'payments.deposit_min' => ['group' => 'payments', 'label' => __('Smallest deposit'), 'help' => '', 'kind' => 'money', 'min' => 100, 'max' => 100_000_000_000],
            'payments.deposit_max' => ['group' => 'payments', 'label' => __('Largest deposit'), 'help' => '', 'kind' => 'money', 'min' => 100, 'max' => 100_000_000_000],
            'payments.withdrawal_min' => ['group' => 'payments', 'label' => __('Smallest withdrawal'), 'help' => '', 'kind' => 'money', 'min' => 100, 'max' => 100_000_000_000],
            'engagement.referral_bonus_referrer' => ['group' => 'engagement', 'label' => __('Referral bonus for the inviter'), 'help' => __('Bonus credit when the invited buyer completes a first order. 0 turns it off.'), 'kind' => 'money', 'min' => 0, 'max' => 100_000_000],
            'engagement.referral_bonus_referred' => ['group' => 'engagement', 'label' => __('Referral bonus for the new buyer'), 'help' => __('Bonus credit paid at the same time.'), 'kind' => 'money', 'min' => 0, 'max' => 100_000_000],
            'billing.grace_days' => ['group' => 'billing', 'label' => __('Grace days after a failed plan payment'), 'help' => __('The company keeps selling this long before the plan expires.'), 'kind' => 'integer', 'min' => 0, 'max' => 60],
        ];
    }

    /** @return array<string, string> */
    public static function groups(): array
    {
        return [
            'bidding' => __('Bidding'),
            'orders' => __('Orders'),
            'payments' => __('Deposits and withdrawals'),
            'billing' => __('Seller plans'),
            'trust' => __('Trust'),
            'tax' => __('Tax'),
            'engagement' => __('Referrals'),
        ];
    }

    /** Lays the stored overrides over the config defaults. Safe before the table exists. */
    public function apply(): void
    {
        self::$defaults ??= collect(self::definitions())->keys()
            ->mapWithKeys(fn (string $key) => [$key => config("auction.{$key}")])
            ->all();

        try {
            /** @var array<string, int> $overrides */
            $overrides = Cache::rememberForever(self::CACHE_KEY, fn () => $this->stored());
        } catch (Throwable) {
            // No database yet (a fresh install, migrations): the defaults stand.
            return;
        }

        foreach (self::$defaults as $key => $default) {
            config(["auction.{$key}" => $overrides[$key] ?? $default]);
        }
    }

    /** The value in force for a key. */
    public function get(string $key): int
    {
        return (int) config("auction.{$key}");
    }

    /** The install default for a key, ignoring any override. */
    public function default(string $key): int
    {
        return (int) (self::$defaults[$key] ?? config("auction.{$key}"));
    }

    /** Called after the stored values change. */
    public function refresh(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->apply();
    }

    /** @return array<string, int> */
    private function stored(): array
    {
        return Setting::query()->whereIn('key', array_keys(self::definitions()))->pluck('value', 'key')
            ->map(fn (mixed $value) => (int) $value)
            ->all();
    }
}
