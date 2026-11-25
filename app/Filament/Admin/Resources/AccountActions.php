<?php

namespace App\Filament\Admin\Resources;

use App\Actions\Accounts\AnonymiseAccount;
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

    /** A buyer's request to delete their data: personal details wiped, records kept. */
    public static function anonymise(): Action
    {
        return Action::make('anonymise')
            ->label(__('Anonymise'))
            ->icon('heroicon-o-user-minus')
            ->color('danger')
            ->modalHeading(__('Anonymise this account'))
            ->modalDescription(__('Name, email, phone, address, picture, identity documents, sign-in history, devices and saved searches are deleted, and the account is closed for good. Bids, orders, invoices and the ledger stay, under "Deleted user". The wallet must be empty and no order open. This cannot be undone.'))
            ->schema([
                Textarea::make('reason')->label(__('Request'))->required()->minLength(3)->maxLength(500)->helperText(__('Where and when the person asked, for the activity log.')),
            ])
            ->visible(fn (User $record) => Gate::allows('anonymise', $record))
            ->action(Outcome::of(fn (User $record, array $data) => app(AnonymiseAccount::class)->handle(Outcome::user(), $record, (string) $data['reason']), __('Account anonymised.')));
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
