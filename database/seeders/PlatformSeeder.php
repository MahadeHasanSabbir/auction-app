<?php

namespace Database\Seeders;

use App\Enums\AccountKind;
use App\Enums\FeeChargeType;
use App\Enums\FeeUseCase;
use App\Enums\SystemAccount;
use App\Enums\UserRole;
use App\Enums\WalletType;
use App\Models\Category;
use App\Models\FeeRule;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserType;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What every installation needs before anyone signs up: the system owner, the
 * platform ledger accounts, and starting user types, plans, fee rules and
 * categories. Safe to run again; it never overwrites what staff changed.
 */
class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        $owner = $this->systemOwner();

        $this->systemWallets();
        $this->userTypes();
        $this->plans();
        $this->feeRules($owner);
        $this->categories();
    }

    private function systemOwner(): User
    {
        $email = (string) config('auction.owner.email');
        $password = config('auction.owner.password');

        if ($password === null && app()->isProduction()) {
            throw new RuntimeException('Set AUCTION_OWNER_PASSWORD before seeding in production.');
        }

        $owner = User::query()->where('email', $email)->first();

        if ($owner !== null) {
            return $owner;
        }

        $owner = new User([
            'name' => (string) config('auction.owner.name'),
            'email' => $email,
            'password' => $password ?? 'admin',
        ]);
        $owner->role = UserRole::SystemOwner;
        $owner->email_verified_at = now();
        $owner->save();

        return $owner;
    }

    private function systemWallets(): void
    {
        foreach (SystemAccount::cases() as $account) {
            Wallet::query()->firstOrCreate(
                ['system_account' => $account],
                ['type' => WalletType::System, 'currency' => config('auction.currency')],
            );
        }
    }

    private function userTypes(): void
    {
        $types = [
            ['Standard buyer', AccountKind::Buyer, true],
            ['Verified buyer', AccountKind::Buyer, false],
            ['Standard seller', AccountKind::Seller, true],
            ['Premium seller', AccountKind::Seller, false],
        ];

        foreach ($types as [$name, $kind, $default]) {
            UserType::query()->firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'account_kind' => $kind, 'is_default' => $default],
            );
        }
    }

    private function plans(): void
    {
        // Prices are in minor units: 150_000 is BDT 1,500.00.
        $plans = [
            ['Starter', 'For a seller working alone or with one colleague.', 150_000, 1_500_000, 3, 10, 14],
            ['Team', 'For a small sales team.', 400_000, 4_000_000, 8, 50, 0],
            ['Business', 'For an auction house with several departments.', 900_000, 9_000_000, 20, null, 0],
        ];

        foreach ($plans as $order => [$name, $description, $monthly, $yearly, $seats, $auctions, $trial]) {
            Plan::query()->firstOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'description' => $description,
                    'price_monthly' => $monthly,
                    'price_yearly' => $yearly,
                    'max_users' => $seats,
                    'max_active_auctions' => $auctions,
                    'trial_days' => $trial,
                    'sort_order' => $order,
                ],
            );
        }
    }

    private function feeRules(User $owner): void
    {
        if (FeeRule::query()->exists()) {
            return;
        }

        // [use case, charge type, gateway, percent bps, fixed, min, max, note]
        $rules = [
            [FeeUseCase::Deposit, FeeChargeType::Gateway, 'sslcommerz', 250, 0, null, null, 'SSLCommerz card and mobile banking charge'],
            [FeeUseCase::Deposit, FeeChargeType::Gateway, 'stripe', 350, 0, null, null, 'Stripe international card charge'],
            [FeeUseCase::Withdrawal, FeeChargeType::Gateway, null, 150, 0, 1_000, 50_000, 'Bank or mobile-wallet transfer charge'],
            [FeeUseCase::Withdrawal, FeeChargeType::Service, null, 50, 0, null, null, 'Withdrawal service charge'],
            [FeeUseCase::SaleCommission, FeeChargeType::Service, null, 1_000, 0, null, null, 'Default commission on a sale'],
            [FeeUseCase::FeaturedListing, FeeChargeType::Service, null, 0, 20_000, null, null, 'Featured listing'],
        ];

        foreach ($rules as [$useCase, $chargeType, $gateway, $percent, $fixed, $min, $max, $note]) {
            FeeRule::query()->create([
                'use_case' => $useCase,
                'charge_type' => $chargeType,
                'gateway' => $gateway,
                'percent_bps' => $percent,
                'fixed_amount' => $fixed,
                'min_amount' => $min,
                'max_amount' => $max,
                'effective_from' => now(),
                'note' => $note,
                'created_by' => $owner->id,
            ]);
        }
    }

    private function categories(): void
    {
        $tree = [
            'Electronics' => ['Phones', 'Computers', 'Cameras'],
            'Vehicles' => ['Cars', 'Motorcycles'],
            'Collectibles' => ['Coins and notes', 'Stamps', 'Antiques'],
            'Art' => ['Paintings', 'Sculpture'],
            'Fashion' => ['Watches', 'Jewellery', 'Textiles'],
            'Home and living' => ['Furniture', 'Handicrafts'],
        ];

        $order = 0;

        foreach ($tree as $parentName => $children) {
            $parent = Category::query()->firstOrCreate(
                ['slug' => Str::slug($parentName)],
                ['name' => $parentName, 'sort_order' => $order++],
            );

            foreach ($children as $position => $childName) {
                Category::query()->firstOrCreate(
                    ['slug' => Str::slug($childName)],
                    ['name' => $childName, 'parent_id' => $parent->id, 'sort_order' => $position],
                );
            }
        }
    }
}
