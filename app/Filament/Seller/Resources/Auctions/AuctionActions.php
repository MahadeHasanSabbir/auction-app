<?php

namespace App\Filament\Seller\Resources\Auctions;

use App\Actions\Auctions\CancelAuction;
use App\Actions\Auctions\FeatureAuction;
use App\Actions\Auctions\SubmitAuction;
use App\Actions\Auctions\WithdrawAuction;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\Company;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** What the seller's team does with an auction, shared by the list and the auction page. */
final class AuctionActions
{
    public static function submit(): Action
    {
        return Action::make('submit')
            ->label(__('Send for review'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(__('Send this auction for review?'))
            ->modalDescription(__('The platform checks the item, pictures and prices, then confirms the schedule. You can take it back to change it while it waits.'))
            ->modalSubmitActionLabel(__('Send for review'))
            ->visible(fn (Auction $record) => $record->status === AuctionStatus::Draft && self::user()->can('submit', $record))
            ->action(self::run(
                fn (Auction $record) => app(SubmitAuction::class)->handle(self::user(), $record),
                fn (Auction $record) => $record->status === AuctionStatus::Scheduled ? __('Approved and scheduled.') : __('Sent for review.'),
            ));
    }

    public static function withdraw(): Action
    {
        return Action::make('withdraw')
            ->label(__('Take back to edit'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('It leaves the review queue and becomes a draft again.'))
            ->visible(fn (Auction $record) => $record->status === AuctionStatus::PendingReview && self::user()->can('submit', $record))
            ->action(self::run(fn (Auction $record) => app(WithdrawAuction::class)->handle(self::user(), $record), fn () => __('Back to draft.')));
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label(__('Cancel auction'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Cancel this auction?'))
            ->modalDescription(fn (Auction $record) => match ($record->status) {
                AuctionStatus::Scheduled => __('It was approved and will not start. This cannot be undone; the product can be listed again.'),
                AuctionStatus::Live => __('Bidding stops now. This cannot be undone; the product can be listed again.'),
                default => __('This cannot be undone; the product can be listed again.'),
            })
            ->modalSubmitActionLabel(__('Cancel auction'))
            ->visible(fn (Auction $record) => in_array($record->status, [AuctionStatus::Draft, AuctionStatus::PendingReview, AuctionStatus::Rejected, AuctionStatus::Scheduled, AuctionStatus::Live], true)
                && self::user()->can('cancel', $record))
            ->action(self::run(fn (Auction $record) => app(CancelAuction::class)->handle(self::user(), $record), fn () => __('Auction cancelled.')));
    }

    /** The owner pays from the company wallet to feature a scheduled or live auction. */
    public static function feature(): Action
    {
        return Action::make('feature')
            ->label(__('Feature'))
            ->icon(Heroicon::OutlinedSparkles)
            ->color('warning')
            ->modalHeading(__('Feature this auction?'))
            ->modalDescription(function (Auction $record): string {
                $fee = app(FeatureAuction::class)->quote($record);

                return $fee === null ? __('Featured listings are not offered right now.') : __('It leads the auction list and shows on the home page until it ends. :amount is paid from the company wallet now and is not refunded if the auction is cancelled.', ['amount' => $fee->amount->format()]);
            })
            ->schema([
                TextInput::make('code')->label(__('Coupon code'))->maxLength(32)->helperText(__('Optional.')),
            ])
            ->modalSubmitActionLabel(__('Pay and feature'))
            ->visible(fn (Auction $record) => in_array($record->status, [AuctionStatus::Scheduled, AuctionStatus::Live], true)
                && $record->featured_at === null
                && self::user()->can('manageBilling', Company::query()->findOrFail($record->company_id)))
            ->action(function (Auction $record, array $data, Action $action): void {
                try {
                    app(FeatureAuction::class)->handle(self::user(), $record, isset($data['code']) ? (string) $data['code'] : null);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title((string) collect($e->errors())->flatten()->first())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title(__('The auction is featured.'))->send();
            });
    }

    /**
     * @param  Closure(Auction): mixed  $callback
     * @param  Closure(Auction): string  $success
     */
    private static function run(Closure $callback, Closure $success): Closure
    {
        return function (Auction $record) use ($callback, $success): void {
            try {
                $callback($record);
            } catch (ValidationException $e) {
                Notification::make()->danger()->title((string) collect($e->errors())->flatten()->first())->send();

                return;
            }

            Notification::make()->success()->title($success($record))->send();
        };
    }

    private static function user(): User
    {
        return Auth::user() ?? abort(401);
    }
}
