<?php

namespace App\Actions\Auctions;

use App\Actions\Auctions\Concerns\AuctionRules;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Creates a draft auction for one of the company's products, or changes a
 * draft. Editing a rejected auction turns it back into a draft, to be sent
 * for review again.
 */
final class SaveAuction
{
    use AuctionRules;

    /**
     * @param  array{product_id?: mixed, title?: mixed, starting_price?: mixed, reserve_price?: mixed, buy_now_price?: mixed, min_increment?: mixed, proposed_starts_at?: mixed, proposed_ends_at?: mixed}  $data
     */
    public function handle(User $user, Company $company, ?Auction $auction, array $data): Auction
    {
        if ($auction === null) {
            Gate::forUser($user)->authorize('create', Auction::class);
            abort_unless($user->company_id === $company->id, 403);
        } else {
            Gate::forUser($user)->authorize('update', $auction);
        }

        $title = trim((string) ($data['title'] ?? ''));

        if (mb_strlen($title) < 3 || mb_strlen($title) > 255) {
            throw ValidationException::withMessages(['title' => __('Give the auction a title of 3 to 255 characters.')]);
        }

        $prices = $this->prices($data);
        $this->checkSchedule($data['proposed_starts_at'] ?? null, $data['proposed_ends_at'] ?? null, 'proposed_starts_at', 'proposed_ends_at');

        return DB::transaction(function () use ($user, $company, $auction, $data, $title, $prices): Auction {
            $product = Product::query()
                ->where('company_id', $company->id)
                ->lockForUpdate()
                ->find($data['product_id'] ?? null);

            if ($product === null) {
                throw ValidationException::withMessages(['product_id' => __('Choose one of your products.')]);
            }

            if ($auction !== null) {
                $auction = Auction::query()->lockForUpdate()->findOrFail($auction->id);

                if (! $auction->status->isEditable()) {
                    throw ValidationException::withMessages(['auction' => __('Only a draft or a rejected auction can be changed.')]);
                }
            }

            if ($product->isSold()) {
                throw ValidationException::withMessages(['product_id' => __('This product has been sold.')]);
            }

            if ($product->hasOpenAuction($auction)) {
                throw ValidationException::withMessages(['product_id' => __('This product already has an auction. Finish or cancel it first.')]);
            }

            if ($auction === null) {
                $auction = new Auction;
                $auction->forceFill(['company_id' => $company->id, 'created_by' => $user->id]);
            } elseif ($auction->status === AuctionStatus::Rejected) {
                $auction->transitionTo(AuctionStatus::Draft);
            }

            $auction->fill([
                'product_id' => $product->id,
                'title' => $title,
                ...$prices,
                'proposed_starts_at' => $this->toDate($data['proposed_starts_at']),
                'proposed_ends_at' => $this->toDate($data['proposed_ends_at']),
            ]);

            // No bid exists yet, so the current price follows the starting price.
            $auction->current_price = $prices['starting_price'];
            $auction->save();

            return $auction;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{starting_price: Money, reserve_price: ?Money, buy_now_price: ?Money, min_increment: Money}
     */
    private function prices(array $data): array
    {
        $starting = $this->money($data, 'starting_price');
        $increment = $this->money($data, 'min_increment');
        $reserve = $this->money($data, 'reserve_price', required: false);
        $buyNow = $this->money($data, 'buy_now_price', required: false);

        if (! $starting->isPositive()) {
            throw ValidationException::withMessages(['starting_price' => __('The starting price must be more than zero.')]);
        }

        if (! $increment->isPositive()) {
            throw ValidationException::withMessages(['min_increment' => __('The bid increment must be more than zero.')]);
        }

        if ($reserve !== null && $reserve->lessThan($starting)) {
            throw ValidationException::withMessages(['reserve_price' => __('The reserve cannot be lower than the starting price.')]);
        }

        if ($buyNow !== null && ! $buyNow->greaterThan($starting)) {
            throw ValidationException::withMessages(['buy_now_price' => __('The buy-now price must be higher than the starting price.')]);
        }

        if ($buyNow !== null && $reserve !== null && $buyNow->lessThan($reserve)) {
            throw ValidationException::withMessages(['buy_now_price' => __('The buy-now price cannot be lower than the reserve.')]);
        }

        return [
            'starting_price' => $starting,
            'reserve_price' => $reserve,
            'buy_now_price' => $buyNow,
            'min_increment' => $increment,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return ($required is true ? Money : ?Money)
     */
    private function money(array $data, string $key, bool $required = true): ?Money
    {
        $value = $data[$key] ?? null;

        if ($value instanceof Money) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d{1,12}(\.\d{1,2})?$/', trim($value)) === 1) {
            return Money::parse(trim($value));
        }

        if ($value === null || $value === '') {
            if ($required) {
                throw ValidationException::withMessages([$key => __('Enter an amount.')]);
            }

            return null;
        }

        throw ValidationException::withMessages([$key => __('Enter an amount such as 1500 or 1500.50.')]);
    }
}
