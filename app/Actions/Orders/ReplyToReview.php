<?php

namespace App\Actions\Orders;

use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Someone on the company's team answers a review, once. */
final class ReplyToReview
{
    public function handle(User $member, Review $review, string $reply): Review
    {
        $review->loadMissing('order');
        Gate::forUser($member)->authorize('reply', $review->order);

        $reply = trim($reply);

        if (mb_strlen($reply) < 2 || mb_strlen($reply) > 2000) {
            throw ValidationException::withMessages(['reply' => __('Write a reply of up to 2,000 characters.')]);
        }

        return DB::transaction(function () use ($review, $reply): Review {
            $locked = Review::query()->lockForUpdate()->findOrFail($review->id);

            if ($locked->reply !== null) {
                throw ValidationException::withMessages(['reply' => __('This review already has a reply.')]);
            }

            $locked->forceFill(['reply' => $reply, 'replied_at' => now()])->save();
            $review->setRawAttributes($locked->getAttributes(), true);

            return $review;
        });
    }
}
