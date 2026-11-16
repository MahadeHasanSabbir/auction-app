<?php

namespace App\Models;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use LogicException;

class User extends Authenticatable implements FilamentUser, HasTenants, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * role, company_id, user_type_id, kyc_status and is_active are left out on
     * purpose: they are set explicitly by the code that is allowed to, never
     * from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'address',
        'avatar_path',
        'locale',
        'timezone',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * A new account is a buyer unless something deliberately says otherwise.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => UserRole::Buyer->value,
        'kyc_status' => KycStatus::NotRequired->value,
        'is_active' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'kyc_status' => KycStatus::class,
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Company roles belong to exactly one company; every other role to none.
        static::saving(function (User $user): void {
            if ($user->role->isSeller() !== ($user->company_id !== null)) {
                throw new LogicException(
                    "A [{$user->role->value}] account ".($user->role->isSeller() ? 'needs a company.' : 'cannot belong to a company.')
                );
            }
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<UserType, $this> */
    public function userType(): BelongsTo
    {
        return $this->belongsTo(UserType::class);
    }

    /** @return HasOne<Wallet, $this> */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    /** @return HasMany<Bid, $this> */
    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    /** @return HasMany<AutoBid, $this> */
    public function autoBids(): HasMany
    {
        return $this->hasMany(AutoBid::class);
    }

    /** @return BelongsToMany<Auction, $this> */
    public function watchedAuctions(): BelongsToMany
    {
        return $this->belongsToMany(Auction::class, 'watchlists')->withPivot('created_at');
    }

    /**
     * Orders this user won as a buyer.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'buyer_id');
    }

    /** @return HasMany<KycRequest, $this> */
    public function kycRequests(): HasMany
    {
        return $this->hasMany(KycRequest::class);
    }

    /** @return HasMany<OtpCode, $this> */
    public function otpCodes(): HasMany
    {
        return $this->hasMany(OtpCode::class);
    }

    /** @return HasMany<TrustedDevice, $this> */
    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class);
    }

    public function isSystemOwner(): bool
    {
        return $this->role === UserRole::SystemOwner;
    }

    public function isPlatform(): bool
    {
        return $this->role->isPlatform();
    }

    public function isSeller(): bool
    {
        return $this->role->isSeller();
    }

    public function isBuyer(): bool
    {
        return $this->role->isBuyer();
    }

    /** Platform roles use the admin panel, company roles the seller panel. */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->isPlatform(),
            'seller' => $this->isSeller(),
            default => false,
        };
    }

    /**
     * The seller panel is scoped to a company; a user has exactly one.
     *
     * @return Collection<int, Company>
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->company()->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Company && $this->company_id === $tenant->getKey();
    }
}
