<?php

namespace App\Filament\Admin\Resources\FraudFlags;

use App\Actions\Trust\ReviewFraudFlag;
use App\Enums\FraudFlagKind;
use App\Enums\FraudFlagStatus;
use App\Filament\Admin\Resources\Auctions\AuctionResource;
use App\Filament\Admin\Resources\Buyers\BuyerResource;
use App\Filament\Admin\Resources\FraudFlags\Pages\ListFraudFlags;
use App\Filament\Support\Outcome;
use App\Models\FraudFlag;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Bids that look wrong (a bidder sharing an IP address with the seller's
 * team), for staff to dismiss or confirm. Confirming can give the bidder a
 * strike; cancelling the auction is done on the auction itself.
 */
class FraudFlagResource extends Resource
{
    protected static ?string $model = FraudFlag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'flags';

    protected static ?string $modelLabel = 'flag';

    public static function getNavigationGroup(): string
    {
        return __('Desk');
    }

    public static function getNavigationLabel(): string
    {
        return __('Flags');
    }

    public static function getNavigationBadge(): ?string
    {
        $open = FraudFlag::query()->where('status', FraudFlagStatus::Open)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
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
        return parent::getEloquentQuery()->with(['user:id,name,email', 'auction:id,title', 'company:id,name', 'reviewer:id,name']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Raised'))->dateTime('j M Y, H:i')->sortable(),
                TextColumn::make('kind')->label(__('What'))->formatStateUsing(fn (FraudFlagKind $state) => $state->label())->wrap(),
                TextColumn::make('user.name')->label(__('Bidder'))->description(fn (FraudFlag $record) => $record->user?->email)
                    ->url(fn (FraudFlag $record) => BuyerResource::getUrl('view', ['record' => $record->user_id])),
                TextColumn::make('auction.title')->label(__('Auction'))->limit(40)
                    ->url(fn (FraudFlag $record) => $record->auction_id === null ? null : AuctionResource::getUrl('view', ['record' => $record->auction_id])),
                TextColumn::make('company.name')->label(__('Seller'))->placeholder('—'),
                TextColumn::make('ip_address')->label(__('IP address'))->copyable(),
                TextColumn::make('team')->label(__('Seller accounts on that IP'))->wrap()
                    ->state(fn (FraudFlag $record) => User::query()->withTrashed()->whereKey($record->details['team_user_ids'] ?? [])->pluck('name')->join(', ')),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (FraudFlagStatus $state) => $state->label())
                    ->color(fn (FraudFlagStatus $state) => $state->color()),
                TextColumn::make('review_note')->label(__('Note'))->placeholder('—')->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reviewer.name')->label(__('Decided by'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('confirm')
                        ->label(__('Confirm'))
                        ->icon('heroicon-o-shield-exclamation')
                        ->color('danger')
                        ->schema([
                            Textarea::make('note')->label(__('What you found'))->required()->minLength(3)->maxLength(500),
                            Toggle::make('strike')->label(__('Give the bidder a strike'))->default(true)->helperText(__('The note is the strike\'s reason and is shown to the bidder.')),
                        ])
                        ->visible(fn (FraudFlag $record) => Gate::allows('review', $record))
                        ->action(Outcome::of(fn (FraudFlag $record, array $data) => app(ReviewFraudFlag::class)->confirm(Outcome::user(), $record, (string) $data['note'], (bool) ($data['strike'] ?? false)), __('Flag confirmed.'))),
                    Action::make('dismiss')
                        ->label(__('Dismiss'))
                        ->icon('heroicon-o-x-mark')
                        ->schema([Textarea::make('note')->label(__('Note'))->maxLength(500)])
                        ->visible(fn (FraudFlag $record) => Gate::allows('review', $record))
                        ->action(Outcome::of(fn (FraudFlag $record, array $data) => app(ReviewFraudFlag::class)->dismiss(Outcome::user(), $record, filled($data['note'] ?? null) ? (string) $data['note'] : null), __('Flag dismissed.'))),
                ]),
            ])
            ->emptyStateHeading(__('No flags'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFraudFlags::route('/'),
        ];
    }
}
