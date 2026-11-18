<?php

namespace App\Filament\Admin\Resources\Staff;

use App\Actions\Accounts\CreateStaffAccount;
use App\Actions\Accounts\ResetStaffPassword;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\AccountActions;
use App\Filament\Admin\Resources\Staff\Pages\ListStaff;
use App\Filament\Support\Outcome;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Platform staff accounts, for the system owner: add one with a temporary
 * password (handed over in person; it must be changed at the first sign-in),
 * give a new one, suspend, reactivate.
 */
class StaffResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'staff';

    protected static ?string $modelLabel = 'staff account';

    public static function getNavigationGroup(): string
    {
        return __('People');
    }

    public static function getNavigationLabel(): string
    {
        return __('Staff');
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('manageStaff', User::class);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('role', UserRole::platform());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->weight('medium')
                    ->description(fn (User $record) => $record->email),
                TextColumn::make('role')->label(__('Role'))->formatStateUsing(fn (UserRole $state) => $state->label()),
                TextColumn::make('is_active')->label(__('Account'))->badge()
                    ->formatStateUsing(fn (User $record) => match (true) {
                        ! $record->is_active => __('Suspended'),
                        $record->password_change_required => __('Temporary password'),
                        default => __('Active'),
                    })
                    ->color(fn (User $record) => match (true) {
                        ! $record->is_active => 'danger',
                        $record->password_change_required => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('last_login_at')->label(__('Last sign-in'))->since()->placeholder(__('Never')),
                TextColumn::make('created_at')->label(__('Added'))->date('j M Y'),
            ])
            ->headerActions([self::create()])
            ->recordActions([
                ActionGroup::make([
                    self::resetPassword(),
                    AccountActions::suspend(),
                    AccountActions::reactivate(),
                ]),
            ])
            ->recordUrl(fn () => null);
    }

    public static function create(): Action
    {
        return Action::make('addStaff')
            ->label(__('Add staff'))
            ->icon('heroicon-o-user-plus')
            ->modalDescription(__('Give the temporary password to the person yourself. They sign in with it and an emailed code, then must choose their own.'))
            ->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(120),
                TextInput::make('email')->label(__('Email'))->email()->required()->maxLength(190),
                TextInput::make('password')->label(__('Temporary password'))->password()->revealable()->required(),
            ])
            ->action(fn (array $data) => Outcome::attempt(
                fn () => app(CreateStaffAccount::class)->handle(Outcome::user(), $data),
                __('Staff account added.'),
            ));
    }

    public static function resetPassword(): Action
    {
        return Action::make('resetPassword')
            ->label(__('New temporary password'))
            ->icon('heroicon-o-key')
            ->color('gray')
            ->modalDescription(__('Signs the person out everywhere; they must choose their own password at the next sign-in.'))
            ->schema([
                TextInput::make('password')->label(__('Temporary password'))->password()->revealable()->required(),
            ])
            ->visible(fn (User $record) => $record->role === UserRole::PlatformStaff)
            ->action(Outcome::of(fn (User $record, array $data) => app(ResetStaffPassword::class)->handle(Outcome::user(), $record, (string) $data['password']), __('Password replaced.')));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaff::route('/'),
        ];
    }
}
