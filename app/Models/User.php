<?php

namespace App\Models;

use App\Enums\KycStatus;
use App\Enums\NotificationCategory;
use App\Enums\OtpPurpose;
use App\Enums\UserRole;
use App\Services\Otp\OtpService;
use App\Services\Otp\OtpThrottledException;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use LogicException;
use NotificationChannels\WebPush\HasPushSubscriptions;
use NotificationChannels\WebPush\PushSubscription;

class User extends Authenticatable implements FilamentUser, HasLocalePreference, HasTenants, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPushSubscriptions, Notifiable, SoftDeletes;

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
            'password_change_required' => 'boolean',
            'notification_preferences' => 'array',
            'referral_rewarded_at' => 'datetime',
            'anonymised_at' => 'datetime',
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

            // Every buyer gets a referral code to share.
            if ($user->role === UserRole::Buyer && $user->referral_code === null) {
                $user->referral_code = self::newReferralCode();
            }
        });
    }

    private static function newReferralCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (self::query()->withTrashed()->where('referral_code', $code)->exists());

        return $code;
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

    /** @return HasMany<Strike, $this> */
    public function strikes(): HasMany
    {
        return $this->hasMany(Strike::class);
    }

    /** @return HasMany<LoginEvent, $this> */
    public function loginEvents(): HasMany
    {
        return $this->hasMany(LoginEvent::class);
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

    /** @return HasMany<SocialAccount, $this> */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
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

    /**
     * Email addresses are confirmed with a code, not a link. Called by the
     * framework when an account is registered.
     */
    public function sendEmailVerificationNotification(): void
    {
        try {
            app(OtpService::class)->send($this, OtpPurpose::EmailVerification, request()->ip());
        } catch (OtpThrottledException) {
            // A code was sent moments ago and is still valid.
        }
    }

    /**
     * How this user appears to other bidders: "Ayesha R." becomes "A****a R.".
     * Full names are only ever shown to the user and to staff.
     */
    /**
     * How this user is known to the other members of a presence channel (the
     * auction viewer count): a stable reference, not the id. Laravel's
     * broadcaster uses it in place of getAuthIdentifier() for presence data.
     */
    public function getAuthIdentifierForBroadcasting(): string
    {
        return substr(hash_hmac('sha256', 'viewer:'.$this->id, (string) config('app.key')), 0, 16);
    }

    public function maskedName(): string
    {
        $parts = preg_split('/\s+/u', trim($this->name)) ?: [];
        $first = $parts[0] ?? '';
        $length = mb_strlen($first);

        $masked = $length <= 2
            ? mb_substr($first, 0, 1).'*'
            : mb_substr($first, 0, 1).str_repeat('*', min($length - 2, 4)).mb_substr($first, -1);

        $last = count($parts) > 1 ? ' '.mb_strtoupper(mb_substr((string) end($parts), 0, 1)).'.' : '';

        return $masked.$last;
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path === null ? null : Storage::disk('public')->url($this->avatar_path);
    }

    /**
     * Whether this user wants a category of notification on a channel: their
     * own choice if they made one, else the default (the bell is on for
     * everything; email only for the important categories).
     *
     * @param  'bell'|'mail'|'push'  $channel
     */
    public function wantsNotification(NotificationCategory $category, string $channel): bool
    {
        $chosen = $this->notification_preferences[$category->value][$channel] ?? null;

        if (is_bool($chosen)) {
            return $chosen;
        }

        // Push needs the browser's own consent first, so it is on wherever the bell is.
        return $channel === 'mail' ? $category->mailByDefault() : true;
    }

    /**
     * The browsers to push to. Queried here rather than read from the
     * relation so a queued notification never lazy-loads it.
     *
     * @return Collection<int, PushSubscription>
     */
    public function routeNotificationForWebPush(): Collection
    {
        return $this->pushSubscriptions()->get();
    }

    /**
     * Emails and notifications are written in the default language: only the
     * site is translated, so a Bangla visitor still gets whole English mails
     * rather than half-translated ones. users.locale holds the site choice.
     */
    public function preferredLocale(): string
    {
        return (string) config('app.locale');
    }

    /** The timezone times are shown in for this user. */
    public function displayTimezone(): string
    {
        return $this->timezone ?? (string) config('auction.display_timezone');
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

    /** The active buyer whose referral code this is, if any. */
    public static function findByReferralCode(mixed $code): ?self
    {
        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        return self::query()
            ->where('referral_code', mb_strtoupper(trim($code)))
            ->where('role', UserRole::Buyer)
            ->where('is_active', true)
            ->first();
    }

    /** The sign-up link that credits this buyer with a referral. */
    public function referralUrl(): ?string
    {
        return $this->referral_code === null ? null : route('register', ['ref' => $this->referral_code]);
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
