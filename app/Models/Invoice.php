<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\InvoiceKind;
use App\Support\Money;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An invoice or receipt, frozen when issued (App\Services\Invoices\Invoices).
 * The PDF is drawn from this row alone. Belongs to a buyer (user_id) or a
 * company (company_id).
 *
 * @property list<array{description: string, amount: int}> $lines
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => InvoiceKind::class,
            'lines' => 'array',
            'total' => MoneyCast::class,
            'taxable' => MoneyCast::class,
            'tax' => MoneyCast::class,
            'issued_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    /** @return list<array{description: string, amount: Money}> */
    public function moneyLines(): array
    {
        return array_map(fn (array $line): array => [
            'description' => (string) $line['description'],
            'amount' => Money::minor((int) $line['amount'], $this->currency),
        ], $this->lines);
    }

    public function fileName(): string
    {
        return "{$this->number}.pdf";
    }
}
