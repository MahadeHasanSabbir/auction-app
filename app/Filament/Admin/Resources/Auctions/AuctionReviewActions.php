<?php

namespace App\Filament\Admin\Resources\Auctions;

use App\Actions\Auctions\ApproveAuction;
use App\Actions\Auctions\CancelAuction;
use App\Actions\Auctions\RejectAuction;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** The platform's decisions on an auction, shared by the review queue and the auction page. */
final class AuctionReviewActions
{
    public static function approve(): Action
    {
        return Action::make('approve')
            ->label(__('Approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalHeading(fn (Auction $record) => __('Approve “:title”', ['title' => $record->title]))
            ->modalDescription(__('The seller’s proposed times are filled in. Change them if needed; a start that has passed becomes now.'))
            ->modalSubmitActionLabel(__('Approve and schedule'))
            ->fillForm(fn (Auction $record) => self::proposedSchedule($record))
            ->schema([
                DateTimePicker::make('starts_at')->label(__('Starts'))->seconds(false)->required(),
                DateTimePicker::make('ends_at')->label(__('Ends'))->seconds(false)->required()->after('starts_at'),
            ])
            ->visible(fn (Auction $record) => $record->status === AuctionStatus::PendingReview && self::user()->can('review', $record))
            ->action(self::run(
                fn (Auction $record, array $data) => app(ApproveAuction::class)->handle(self::user(), $record, $data['starts_at'] ?? null, $data['ends_at'] ?? null),
                __('Auction approved and scheduled.'),
            ));
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label(__('Reject'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading(fn (Auction $record) => __('Send “:title” back', ['title' => $record->title]))
            ->modalSubmitActionLabel(__('Reject'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('What the seller should change'))
                    ->helperText(__('Shown to the seller.'))
                    ->required()
                    ->maxLength(1000)
                    ->rows(4),
            ])
            ->visible(fn (Auction $record) => $record->status === AuctionStatus::PendingReview && self::user()->can('review', $record))
            ->action(self::run(
                fn (Auction $record, array $data) => app(RejectAuction::class)->handle(self::user(), $record, (string) $data['reason']),
                __('Auction sent back to the seller.'),
            ));
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label(__('Cancel auction'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('gray')
            ->modalHeading(__('Cancel this auction for the platform?'))
            ->modalDescription(__('It stops (or will not start) and the leading bidder’s money is released. This cannot be undone.'))
            ->modalSubmitActionLabel(__('Cancel auction'))
            ->schema([
                Textarea::make('reason')->label(__('Reason'))->helperText(__('Shown to the seller.'))->required()->maxLength(1000)->rows(3),
            ])
            ->visible(fn (Auction $record) => in_array($record->status, [AuctionStatus::Scheduled, AuctionStatus::Live], true) && self::user()->can('review', $record))
            ->action(self::run(
                fn (Auction $record, array $data) => app(CancelAuction::class)->handle(self::user(), $record, (string) $data['reason']),
                __('Auction cancelled.'),
            ));
    }

    /**
     * The seller's proposal; when its start has passed, from now for the
     * same duration.
     *
     * @return array{starts_at: ?CarbonImmutable, ends_at: ?CarbonImmutable}
     */
    public static function proposedSchedule(Auction $auction): array
    {
        $starts = $auction->proposed_starts_at?->toImmutable();
        $ends = $auction->proposed_ends_at?->toImmutable();

        if ($starts !== null && $ends !== null && $starts->isPast()) {
            $now = now()->toImmutable()->addMinute()->startOfMinute();
            $ends = $now->addSeconds((int) $starts->diffInSeconds($ends));
            $starts = $now;
        }

        return ['starts_at' => $starts, 'ends_at' => $ends];
    }

    /** @param  Closure(Auction, array<string, mixed>): mixed  $callback */
    private static function run(Closure $callback, string $success): Closure
    {
        return function (Auction $record, array $data, Action $action) use ($callback, $success): void {
            try {
                $callback($record, $data);
            } catch (ValidationException $e) {
                Notification::make()->danger()->title((string) collect($e->errors())->flatten()->first())->send();
                $action->halt();
            }

            Notification::make()->success()->title($success)->send();
        };
    }

    private static function user(): User
    {
        return Auth::user() ?? abort(401);
    }
}
