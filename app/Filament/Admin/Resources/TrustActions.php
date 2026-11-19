<?php

namespace App\Filament\Admin\Resources;

use App\Actions\Trust\IssueFine;
use App\Actions\Trust\IssueStrike;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\Outcome;
use App\Models\Company;
use App\Models\Strike;
use App\Models\User;
use App\Models\Wallet;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Gate;

/** Strikes and fines from a buyer's or a company's page. */
final class TrustActions
{
    public static function strike(): Action
    {
        return Action::make('strike')
            ->label(__('Give a strike'))
            ->icon('heroicon-o-exclamation-triangle')
            ->color('warning')
            ->modalDescription(fn () => __('The buyer is told why. :limit active strikes suspend the account.', ['limit' => config('auction.trust.strike_limit')]))
            ->schema([
                Textarea::make('reason')->label(__('Reason'))->required()->minLength(3)->maxLength(500)->helperText(__('Shown to the buyer.')),
            ])
            ->visible(fn (User $record) => Gate::allows('issue', [Strike::class, $record]))
            ->action(Outcome::of(fn (User $record, array $data) => app(IssueStrike::class)->handle(Outcome::user(), $record, (string) $data['reason']), __('Strike given.')));
    }

    public static function fine(): Action
    {
        return Action::make('fine')
            ->label(__('Fine'))
            ->icon('heroicon-o-receipt-percent')
            ->color('danger')
            ->modalDescription(__('The amount is taken from the available balance and a fine notice is issued.'))
            ->schema([
                MoneyInput::make('amount')->label(__('Amount'))->required(),
                Textarea::make('reason')->label(__('Reason'))->required()->minLength(3)->maxLength(500)->helperText(__('Shown to the payer and on the notice.')),
            ])
            ->visible(fn (User|Company $record) => ($wallet = self::walletOf($record)) !== null && Gate::allows('fine', $wallet))
            ->action(Outcome::of(fn (User|Company $record, array $data) => app(IssueFine::class)->handle(
                Outcome::user(),
                self::walletOf($record) ?? abort(404),
                $data['amount'],
                (string) $data['reason'],
            ), __('Fine taken.')));
    }

    private static function walletOf(User|Company $record): ?Wallet
    {
        return $record instanceof Company
            ? Wallet::query()->where('company_id', $record->id)->first()
            : Wallet::query()->where('user_id', $record->id)->first();
    }
}
