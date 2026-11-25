<?php

namespace App\Filament\Admin\Resources\Orders;

use App\Actions\Orders\ResolveDispute;
use App\Enums\DisputeResolution;
use App\Enums\OrderStatus;
use App\Filament\Support\Outcome;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Gate;

/** What platform staff do to an order: decide its dispute. */
final class OrderActions
{
    public static function resolve(): Action
    {
        return Action::make('resolve')
            ->label(__('Decide dispute'))
            ->icon('heroicon-o-scale')
            ->color('danger')
            ->modalDescription(fn (Order $record) => __('The escrow of :amount is settled at once and both sides are told. This cannot be undone.', ['amount' => $record->amount->format()]))
            ->schema([
                Radio::make('resolution')
                    ->label(__('Decision'))
                    ->options(collect(DisputeResolution::cases())->mapWithKeys(fn (DisputeResolution $resolution) => [$resolution->value => $resolution->label()])->all())
                    ->required()
                    ->live(),
                TextInput::make('refund')
                    ->label(__('Amount to refund'))
                    ->suffix((string) config('auction.currency'))
                    ->inputMode('decimal')
                    ->rule('regex:/^\d{1,12}(\.\d{1,2})?$/')
                    ->required(fn (Get $get) => $get('resolution') === DisputeResolution::RefundPartial->value)
                    ->visible(fn (Get $get) => $get('resolution') === DisputeResolution::RefundPartial->value)
                    ->helperText(__('The seller gets the rest, less a proportionally smaller commission.')),
                Textarea::make('note')
                    ->label(__('Explanation'))
                    ->required()
                    ->minLength(3)
                    ->maxLength(2000)
                    ->helperText(__('Shown to the buyer and the seller.')),
            ])
            ->visible(fn (Order $record) => $record->status === OrderStatus::Disputed && Gate::allows('resolve', $record))
            ->action(Outcome::of(
                fn (Order $record, array $data) => app(ResolveDispute::class)->handle(
                    Outcome::user(),
                    $record,
                    DisputeResolution::from((string) $data['resolution']),
                    (string) $data['note'],
                    isset($data['refund']) ? (string) $data['refund'] : null,
                ),
                __('Dispute decided.'),
            ));
    }
}
