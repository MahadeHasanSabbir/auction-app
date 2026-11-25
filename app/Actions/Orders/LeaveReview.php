<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\ChangesOrders;
use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The buyer rates the seller of a completed order, once, within
 * `auction.orders.review_days`. The company's rating cache moves in the same
 * transaction.
 */
final class LeaveReview
{
    use ChangesOrders;

    /** @param  array<string, mixed>  $input */
    public function handle(User $buyer, Order $order, array $input): Review
    {
        Gate::forUser($buyer)->authorize('review', $order);

        /** @var array{rating: int|string, comment?: ?string} $data */
        $data = Validator::make($input, [
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($buyer, $order, $data): Review {
            $locked = $this->lockOrder($order, OrderStatus::Completed);

            if (Review::query()->where('order_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['rating' => __('You have already reviewed this order.')]);
            }

            $company = Company::query()->lockForUpdate()->findOrFail($locked->company_id);
            $rating = (int) $data['rating'];
            $comment = trim((string) ($data['comment'] ?? ''));

            $review = new Review;
            $review->forceFill([
                'order_id' => $locked->id,
                'company_id' => $company->id,
                'buyer_id' => $buyer->id,
                'rating' => $rating,
                'comment' => $comment === '' ? null : $comment,
                'is_visible' => true,
            ])->save();

            $company->forceFill([
                'rating_sum' => $company->rating_sum + $rating,
                'rating_count' => $company->rating_count + 1,
            ])->save();

            $this->record($locked, 'reviewed', trans_choice('The buyer rated the seller :count star.|The buyer rated the seller :count stars.', $rating), $buyer);

            return $review;
        });
    }
}
