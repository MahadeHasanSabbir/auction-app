<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\SystemAccount;
use App\Enums\WalletType;
use App\Events\Account\WalletUpdated;
use App\Support\Money;
use Database\Factories\WalletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A ledger account: one per buyer, per company and per platform account.
 *
 * balance and held are caches. They change only together with the ledger
 * entries and holds they summarise, inside a transaction that holds a lock on
 * this row; nothing else may write them.
 */
class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['type', 'user_id', 'company_id', 'system_account', 'currency'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'balance' => 0,
        'held' => 0,
    ];

    protected static function booted(): void
    {
        // A buyer's open pages show the new balance (sent after the transaction commits).
        static::updated(function (Wallet $wallet): void {
            if ($wallet->user_id !== null && $wallet->wasChanged(['balance', 'held'])) {
                WalletUpdated::dispatch($wallet);
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => WalletType::class,
            'system_account' => SystemAccount::class,
            'balance' => MoneyCast::class,
            'held' => MoneyCast::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /** @return HasMany<WalletHold, $this> */
    public function holds(): HasMany
    {
        return $this->hasMany(WalletHold::class);
    }

    /** @return HasMany<Deposit, $this> */
    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }

    /** @return HasMany<WithdrawalRequest, $this> */
    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(WithdrawalRequest::class);
    }

    /** What the owner can spend or withdraw: the balance minus what is on hold. */
    public function available(): Money
    {
        return $this->balance->minus($this->held);
    }

    public static function forSystem(SystemAccount $account): self
    {
        return self::query()->where('system_account', $account)->firstOrFail();
    }
}
