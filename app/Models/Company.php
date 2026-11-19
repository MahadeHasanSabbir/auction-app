<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Enums\KycStatus;
use App\Enums\UserRole;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, SoftDeletes;

    /**
     * status, kyc_status, auto_approve and the rating cache are set by the
     * platform and by actions, never from a company form.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name', 'slug', 'legal_name', 'email', 'phone', 'address', 'logo_path', 'banner_path', 'about',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => CompanyStatus::Pending->value,
        'kyc_status' => KycStatus::NotRequired->value,
        'auto_approve' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'kyc_status' => KycStatus::class,
            'auto_approve' => 'boolean',
            'verified_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * Everyone on the team, the owner included.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasOne<User, $this> */
    public function owner(): HasOne
    {
        return $this->hasOne(User::class)->where('role', UserRole::CompanyOwner);
    }

    /** @return BelongsTo<UserType, $this> */
    public function userType(): BelongsTo
    {
        return $this->belongsTo(UserType::class);
    }

    /** @return HasOne<Subscription, $this> */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /** @return HasMany<SubscriptionInvoice, $this> */
    public function subscriptionInvoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    /** @return HasMany<CompanyAgreement, $this> */
    public function agreements(): HasMany
    {
        return $this->hasMany(CompanyAgreement::class);
    }

    /** @return HasMany<CompanyInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(CompanyInvitation::class);
    }

    /** @return HasOne<Wallet, $this> */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @return HasMany<Auction, $this> */
    public function auctions(): HasMany
    {
        return $this->hasMany(Auction::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** @return HasMany<KycRequest, $this> */
    public function kycRequests(): HasMany
    {
        return $this->hasMany(KycRequest::class);
    }

    /** Seats taken: everyone on the team plus invitations still waiting to be accepted. */
    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function seatsUsed(): int
    {
        return $this->users()->count() + $this->invitations()->pending()->count();
    }

    /**
     * The "verified seller" badge: the platform verified the company and its
     * identity documents are cleared.
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null && $this->kyc_status->isCleared();
    }

    public function isSuspended(): bool
    {
        return $this->status === CompanyStatus::Suspended;
    }

    /** Average rating to one decimal; null before the first review. */
    public function averageRating(): ?float
    {
        return $this->rating_count === 0 ? null : round($this->rating_sum / $this->rating_count, 1);
    }
}
