<?php

namespace App\Filament\Admin\Resources;

use App\Actions\Accounts\ReactivateAccount;
use App\Actions\Accounts\SuspendAccount;
use App\Filament\Support\Outcome;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Gate;

/** Suspend and reactivate, for buyers and (by the system owner) staff accounts. */
final class AccountActions
{
    public static function suspend(): Action
    {
        return Action::make('suspend')
            ->label(__('Suspend'))
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->modalDescription(__('The account is signed out and cannot sign in. Bids already placed stand; the wallet and orders stay.'))
            ->schema([
                Textarea::make('reason')->label(__('Reason'))->required()->minLength(3)->maxLength(500)->helperText(__('Kept for the team; not shown to the person.')),
            ])
            ->visible(fn (User $record) => Gate::allows('suspend', $record))
            ->action(Outcome::of(fn (User $record, array $data) => app(SuspendAccount::class)->handle(Outcome::user(), $record, (string) $data['reason']), __('Account suspended.')));
    }

    public static function reactivate(): Action
    {
        return Action::make('reactivate')
            ->label(__('Reactivate'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->requiresConfirmation()
            ->visible(fn (User $record) => Gate::allows('reactivate', $record))
            ->action(Outcome::of(fn (User $record) => app(ReactivateAccount::class)->handle(Outcome::user(), $record), __('Account reactivated.')));
    }
}
