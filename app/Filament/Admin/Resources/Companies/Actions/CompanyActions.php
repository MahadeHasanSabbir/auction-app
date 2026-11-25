<?php

namespace App\Filament\Admin\Resources\Companies\Actions;

use App\Actions\Companies\AssignPlan;
use App\Actions\Companies\ReinstateCompany;
use App\Actions\Companies\SetAutoApprove;
use App\Actions\Companies\SuspendCompany;
use App\Actions\Companies\VerifyCompany;
use App\Enums\BillingInterval;
use App\Enums\CompanyStatus;
use App\Filament\Support\MoneyInput;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * The decisions the system owner makes about a company, shared by the list
 * and the company page. Each one calls the same action class as any other
 * caller; a refusal is shown as a notice instead of an error page.
 */
final class CompanyActions
{
    public static function verify(): Action
    {
        return Action::make('verify')
            ->label(__('Verify'))
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('Confirm this company is genuine. It can list once its plan allows.'))
            ->visible(fn (Company $record) => $record->status === CompanyStatus::Pending)
            ->action(self::run(fn (Company $record) => app(VerifyCompany::class)->handle(self::user(), $record), __('Company verified.')));
    }

    public static function suspend(): Action
    {
        return Action::make('suspend')
            ->label(__('Suspend'))
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->modalDescription(__('A suspended company cannot create listings or add people. Its running orders and wallet stay.'))
            ->schema([
                Textarea::make('reason')->label(__('Reason'))->required()->maxLength(500)->helperText(__('Shown to the company.')),
            ])
            ->visible(fn (Company $record) => $record->status !== CompanyStatus::Suspended)
            ->action(self::run(fn (Company $record, array $data) => app(SuspendCompany::class)->handle(self::user(), $record, (string) $data['reason']), __('Company suspended.')));
    }

    public static function reinstate(): Action
    {
        return Action::make('reinstate')
            ->label(__('Reinstate'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->requiresConfirmation()
            ->visible(fn (Company $record) => $record->status === CompanyStatus::Suspended)
            ->action(self::run(fn (Company $record) => app(ReinstateCompany::class)->handle(self::user(), $record), __('Company reinstated.')));
    }

    public static function autoApprove(): Action
    {
        return Action::make('autoApprove')
            ->label(fn (Company $record) => $record->auto_approve ? __('Require review again') : __('Auto-approve auctions'))
            ->icon('heroicon-o-bolt')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(fn (Company $record) => $record->auto_approve
                ? __('Each auction of this company will need platform approval again.')
                : __('Auctions of this company will go live without platform review.'))
            ->action(self::run(fn (Company $record) => app(SetAutoApprove::class)->handle(self::user(), $record, ! $record->auto_approve), __('Saved.')));
    }

    public static function assignPlan(): Action
    {
        return Action::make('assignPlan')
            ->label(__('Assign plan'))
            ->icon('heroicon-o-credit-card')
            ->color('gray')
            ->modalDescription(__('Puts the company on this plan from now, with no invoice. Use it for custom plans, agreed prices and limits.'))
            ->schema([
                Select::make('plan_id')
                    ->label(__('Plan'))
                    ->options(fn () => Plan::query()->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->required(),
                Select::make('interval')
                    ->label(__('Billing interval'))
                    ->options(collect(BillingInterval::cases())->mapWithKeys(fn (BillingInterval $interval) => [$interval->value => $interval->label()])->all())
                    ->default(BillingInterval::Monthly->value)
                    ->required(),
                MoneyInput::make('price')->label(__('Agreed price per period'))->helperText(__('Leave empty to use the plan price.')),
                TextInput::make('max_users')->label(__('Team seats'))->helperText(__('Leave empty to use the plan limit.'))->integer()->minValue(1),
                TextInput::make('max_active_auctions')->label(__('Active auctions'))->helperText(__('Leave empty to use the plan limit.'))->integer()->minValue(1),
            ])
            ->action(self::run(
                fn (Company $record, array $data) => app(AssignPlan::class)->handle(
                    self::user(),
                    $record,
                    Plan::query()->findOrFail($data['plan_id']),
                    BillingInterval::from($data['interval']),
                    $data['price'] ?? null,
                    filled($data['max_users'] ?? null) ? (int) $data['max_users'] : null,
                    filled($data['max_active_auctions'] ?? null) ? (int) $data['max_active_auctions'] : null,
                ),
                __('Plan assigned.'),
            ));
    }

    /**
     * Runs an action class for the record and reports the outcome.
     *
     * @param  Closure(Company, array<string, mixed>): mixed  $callback
     */
    private static function run(Closure $callback, string $success): Closure
    {
        return function (Company $record, array $data) use ($callback, $success): void {
            try {
                $callback($record, $data);
            } catch (ValidationException $e) {
                Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();

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
