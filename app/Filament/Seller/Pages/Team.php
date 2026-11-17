<?php

namespace App\Filament\Seller\Pages;

use App\Actions\Team\InviteMember;
use App\Actions\Team\RemoveMember;
use App\Actions\Team\RevokeInvitation;
use App\Enums\UserRole;
use App\Filament\Seller\Pages\Concerns\ForCompany;
use App\Models\CompanyInvitation;
use App\Models\Subscription;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * The company's people: who is on the team, who has been invited, and how
 * many seats are left.
 *
 * @property-read Collection<int, CompanyInvitation> $invitations
 * @property-read int $seats
 */
class Team extends Page implements HasTable
{
    use ForCompany;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'team';

    protected string $view = 'filament.seller.pages.team';

    public static function getNavigationLabel(): string
    {
        return __('Team');
    }

    public function getTitle(): string
    {
        return __('Team');
    }

    public function getSubheading(): string
    {
        return $this->seats === 0
            ? __('Choose a plan to add people.')
            : __(':used of :seats seats taken, counting invitations not yet accepted.', ['used' => $this->company()->seatsUsed(), 'seats' => $this->seats]);
    }

    /** @return Collection<int, CompanyInvitation> */
    #[Computed]
    public function invitations(): Collection
    {
        return CompanyInvitation::query()->with('inviter')->where('company_id', $this->company()->id)->pending()->latest('id')->get();
    }

    #[Computed]
    public function seats(): int
    {
        $subscription = Subscription::query()->with('plan')->where('company_id', $this->company()->id)->first();

        return $subscription?->maxUsers() ?? 0;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label(__('Invite someone'))
                ->icon(Heroicon::OutlinedUserPlus)
                ->visible(fn () => $this->canManage())
                ->disabled(fn () => $this->company()->seatsUsed() >= $this->seats)
                ->tooltip(fn () => $this->company()->seatsUsed() >= $this->seats ? __('All seats are taken. Upgrade the plan or remove someone first.') : null)
                ->modalHeading(__('Invite someone to the team'))
                ->modalDescription(__('We email them a link to create their account. The invitation holds a seat until it is accepted, withdrawn or expires.'))
                ->modalSubmitActionLabel(__('Send invitation'))
                ->modalWidth('md')
                ->schema([
                    TextInput::make('email')->label(__('Work email'))->email()->required()->maxLength(255)->autofocus(),
                ])
                ->action(function (array $data, Action $action, InviteMember $invite): void {
                    if ($this->attempt(fn () => $invite->handle($this->user(), $this->company(), (string) $data['email']), $action)) {
                        $this->toast(__('Invitation sent to :email.', ['email' => $data['email']]));
                        unset($this->invitations);
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(User::query()->where('company_id', $this->company()->id))
            ->defaultSort('role')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->weight('medium')
                    ->description(fn (User $record) => $record->email)
                    ->formatStateUsing(fn (string $state, User $record) => $record->id === $this->user()->id ? $state.' ('.__('you').')' : $state),
                TextColumn::make('role')
                    ->label(__('Role'))
                    ->badge()
                    ->formatStateUsing(fn (UserRole $state) => $state === UserRole::CompanyOwner ? __('Owner') : __('Member'))
                    ->color(fn (UserRole $state) => $state === UserRole::CompanyOwner ? 'primary' : 'gray'),
                TextColumn::make('last_login_at')
                    ->label(__('Last signed in'))
                    ->since()
                    ->placeholder(__('Never')),
                TextColumn::make('created_at')
                    ->label(__('Joined'))
                    ->date('j M Y'),
            ])
            ->recordActions([
                Action::make('remove')
                    ->label(__('Remove'))
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->color('danger')
                    ->visible(fn (User $record) => $this->canManage() && $record->role === UserRole::CompanyMember)
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record) => __('Remove :name?', ['name' => $record->name]))
                    ->modalDescription(__('They are signed out at once and can no longer open the seller panel. Their past listings stay.'))
                    ->modalSubmitActionLabel(__('Remove'))
                    ->action(function (User $record, RemoveMember $remove): void {
                        if ($this->attempt(fn () => $remove->handle($this->user(), $this->company(), $record))) {
                            $this->toast(__(':name was removed from the team.', ['name' => $record->name]));
                        }
                    }),
            ]);
    }

    public function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label(__('Withdraw'))
            ->color('gray')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading(__('Withdraw this invitation?'))
            ->modalDescription(__('The link stops working and the seat is free again.'))
            ->action(function (array $arguments, RevokeInvitation $revoke): void {
                $invitation = CompanyInvitation::query()->where('company_id', $this->company()->id)->findOrFail($arguments['invitation'] ?? 0);

                $revoke->handle($this->user(), $this->company(), $invitation);

                $this->toast(__('Invitation withdrawn.'));
                unset($this->invitations);
            });
    }
}
