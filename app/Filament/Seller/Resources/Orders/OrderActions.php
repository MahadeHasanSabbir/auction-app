<?php

namespace App\Filament\Seller\Resources\Orders;

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\ReplyToReview;
use App\Actions\Orders\ShipOrder;
use App\Models\Order;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** What the seller's team does with an order, shared by the list and the order page. */
final class OrderActions
{
    public static function ship(): Action
    {
        return Action::make('ship')
            ->label(__('Mark as shipped'))
            ->icon(Heroicon::OutlinedTruck)
            ->color('primary')
            ->modalHeading(__('Ship this order'))
            ->modalDescription(__('The buyer sees the courier and tracking. The payout follows when they confirm delivery, or automatically after :days days.', [
                'days' => config('auction.orders.auto_release_days'),
            ]))
            ->modalSubmitActionLabel(__('Mark as shipped'))
            ->schema([
                TextInput::make('courier')->label(__('Courier'))->required()->maxLength(255),
                TextInput::make('tracking_number')->label(__('Tracking number'))->maxLength(255),
                TextInput::make('tracking_url')->label(__('Tracking link'))->url()->maxLength(500),
                Textarea::make('note')->label(__('Note for the buyer'))->maxLength(500)->rows(2),
            ])
            ->visible(fn (Order $record) => self::user()->can('ship', $record) && filled($record->shipping_address))
            ->action(self::run(
                fn (Order $record, array $data) => app(ShipOrder::class)->handle(self::user(), $record, $data),
                __('Marked as shipped.'),
            ));
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label(__('Cancel order'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Cancel this order?'))
            ->modalDescription(__('The buyer is refunded in full and you receive nothing. This cannot be undone; the product can be listed again.'))
            ->modalSubmitActionLabel(__('Cancel order'))
            ->schema([
                Textarea::make('reason')->label(__('Reason, shown to the buyer'))->required()->minLength(3)->maxLength(500)->rows(3),
            ])
            ->visible(fn (Order $record) => self::user()->can('cancel', $record))
            ->action(self::run(
                fn (Order $record, array $data) => app(CancelOrder::class)->handle(self::user(), $record, (string) $data['reason']),
                __('Order cancelled and the buyer refunded.'),
            ));
    }

    public static function reply(): Action
    {
        return Action::make('reply')
            ->label(__('Reply to review'))
            ->icon(Heroicon::OutlinedChatBubbleLeft)
            ->color('gray')
            ->modalDescription(__('Your reply is public, under the review. You can reply once.'))
            ->schema([
                Textarea::make('reply')->label(__('Reply'))->required()->minLength(2)->maxLength(2000)->rows(4),
            ])
            ->visible(fn (Order $record) => $record->review !== null && $record->review->reply === null && self::user()->can('reply', $record))
            ->action(self::run(
                fn (Order $record, array $data) => app(ReplyToReview::class)->handle(self::user(), $record->review ?? abort(404), (string) $data['reply']),
                __('Reply posted.'),
            ));
    }

    /** @param  Closure(Order, array<string, mixed>): mixed  $callback */
    private static function run(Closure $callback, string $success): Closure
    {
        return function (Order $record, array $data) use ($callback, $success): void {
            try {
                $callback($record, $data);
            } catch (ValidationException $e) {
                Notification::make()->danger()->title((string) collect($e->errors())->flatten()->first())->send();

                return;
            }

            Notification::make()->success()->title($success)->send();
        };
    }

    private static function user(): User
    {
        return Auth::user() ?? abort(401);
    }
}
