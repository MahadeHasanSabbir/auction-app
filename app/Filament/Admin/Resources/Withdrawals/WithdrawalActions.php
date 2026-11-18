<?php

namespace App\Filament\Admin\Resources\Withdrawals;

use App\Actions\Wallet\ApproveWithdrawal;
use App\Actions\Wallet\PayWithdrawal;
use App\Actions\Wallet\RejectWithdrawal;
use App\Enums\WithdrawalStatus;
use App\Filament\Support\Outcome;
use App\Models\WithdrawalRequest;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Gate;

/** Approve, pay and reject, shared by the list and the request page. */
final class WithdrawalActions
{
    public static function approve(): Action
    {
        return Action::make('approve')
            ->label(__('Approve'))
            ->icon('heroicon-o-check')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('The amount stays on hold until you mark it paid.'))
            ->visible(fn (WithdrawalRequest $record) => $record->status === WithdrawalStatus::Pending && self::mayReview($record))
            ->action(Outcome::of(fn (WithdrawalRequest $record) => app(ApproveWithdrawal::class)->handle(Outcome::user(), $record), __('Withdrawal approved.')));
    }

    public static function pay(): Action
    {
        return Action::make('pay')
            ->label(__('Mark paid'))
            ->icon('heroicon-o-banknotes')
            ->color('primary')
            ->modalDescription(fn (WithdrawalRequest $record) => __('Send :amount yourself first, then record the transfer here. The wallet is debited.', ['amount' => $record->net_amount->format()]))
            ->schema([
                TextInput::make('reference')->label(__('Transfer reference'))->required()->maxLength(190),
            ])
            ->visible(fn (WithdrawalRequest $record) => $record->status === WithdrawalStatus::Approved && self::mayReview($record))
            ->action(Outcome::of(fn (WithdrawalRequest $record, array $data) => app(PayWithdrawal::class)->handle(Outcome::user(), $record, (string) $data['reference']), __('Withdrawal paid.')));
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label(__('Reject'))
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->modalDescription(__('The amount is released back to the wallet.'))
            ->schema([
                Textarea::make('reason')->label(__('Reason'))->required()->maxLength(500)->helperText(__('Shown to the person who asked.')),
            ])
            ->visible(fn (WithdrawalRequest $record) => $record->status->isOpen() && self::mayReview($record))
            ->action(Outcome::of(fn (WithdrawalRequest $record, array $data) => app(RejectWithdrawal::class)->handle(Outcome::user(), $record, (string) $data['reason']), __('Withdrawal rejected.')));
    }

    private static function mayReview(WithdrawalRequest $record): bool
    {
        return Gate::allows('review', $record);
    }
}
