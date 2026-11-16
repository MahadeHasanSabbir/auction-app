<?php

namespace App\Actions\Accounts;

use App\Enums\AccountKind;
use App\Enums\UserRole;
use App\Enums\WalletType;
use App\Models\Company;
use App\Models\User;
use App\Models\UserType;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a seller company, its owner account and the company wallet.
 *
 * The company starts as "pending": it can set itself up in the seller panel,
 * but the platform verifies it before it can list. Choosing a plan happens in
 * the panel. The caller validates the input and fires the Registered event.
 */
final class RegisterCompany
{
    /**
     * @param  array{name: string, email: string, password: string, company_name: string, phone?: string|null}  $data
     */
    public function handle(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $company = new Company([
                'name' => $data['company_name'],
                'slug' => $this->uniqueSlug($data['company_name']),
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
            ]);
            $company->user_type_id = UserType::query()
                ->where('account_kind', AccountKind::Seller)
                ->where('is_default', true)
                ->where('is_active', true)
                ->value('id');
            $company->save();

            $owner = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'phone' => $data['phone'] ?? null,
            ]);
            $owner->role = UserRole::CompanyOwner;
            $owner->company_id = $company->id;
            $owner->save();

            Wallet::query()->create([
                'type' => WalletType::Company,
                'company_id' => $company->id,
                'currency' => config('auction.currency'),
            ]);

            return $owner;
        });
    }

    /**
     * The slug is the company's address in the seller panel and, later, its
     * storefront. Soft-deleted companies keep theirs, so they count as taken.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'company';
        $slug = $base;

        for ($suffix = 2; Company::withTrashed()->where('slug', $slug)->exists(); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
