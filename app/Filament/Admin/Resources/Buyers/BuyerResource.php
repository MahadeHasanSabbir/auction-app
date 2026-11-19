<?php

namespace App\Filament\Admin\Resources\Buyers;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\AccountActions;
use App\Filament\Admin\Resources\Buyers\Pages\ListBuyers;
use App\Filament\Admin\Resources\Buyers\Pages\ViewBuyer;
use App\Filament\Admin\Resources\Buyers\RelationManagers\LoginEventsRelationManager;
use App\Filament\Admin\Resources\Buyers\RelationManagers\OrdersRelationManager;
use App\Filament\Admin\Resources\Buyers\RelationManagers\StrikesRelationManager;
use App\Filament\Admin\Resources\Kyc\KycRequestResource;
use App\Filament\Admin\Resources\Kyc\KycRequestsRelationManager;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Buyer accounts: look them up, see their wallet and orders, suspend or
 * reactivate. Nothing about a buyer is edited here, and no money is moved.
 */
class BuyerResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'buyers';

    protected static ?string $modelLabel = 'buyer';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return __('People');
    }

    public static function getNavigationLabel(): string
    {
        return __('Buyers');
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
        return parent::getEloquentQuery()
            ->where('role', UserRole::Buyer)
            ->with(['wallet'])
            ->withCount(['orders', 'bids']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable()->weight('medium')
                    ->description(fn (User $record) => $record->email),
                TextColumn::make('phone')->label(__('Phone'))->searchable()->placeholder('—')->toggleable(),
                IconColumn::make('email_verified_at')->label(__('Verified'))->boolean()
                    ->state(fn (User $record) => $record->email_verified_at !== null),
                TextColumn::make('kyc_status')->label(__('Identity'))->badge()
                    ->formatStateUsing(fn (KycStatus $state) => $state->label())->color(fn (KycStatus $state) => $state->color()),
                TextColumn::make('wallet.balance')->label(__('Balance'))->formatStateUsing(fn ($state) => $state?->format())->alignEnd()->placeholder('—'),
                TextColumn::make('bids_count')->label(__('Bids'))->alignEnd(),
                TextColumn::make('orders_count')->label(__('Orders'))->alignEnd(),
                TextColumn::make('is_active')->label(__('Account'))->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? __('Active') : __('Suspended'))
                    ->color(fn (bool $state) => $state ? 'success' : 'danger'),
                TextColumn::make('created_at')->label(__('Joined'))->date('j M Y')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('Account'))
                    ->trueLabel(__('Active'))->falseLabel(__('Suspended')),
                TernaryFilter::make('email_verified_at')->label(__('Email'))->nullable()
                    ->trueLabel(__('Verified'))->falseLabel(__('Not verified')),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([AccountActions::suspend(), AccountActions::reactivate()]),
            ])
            ->emptyStateHeading(__('No buyers yet'));
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn ($state) => $state?->format();

        return $schema
            ->columns(3)
            ->components([
                Section::make(__('Suspended'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->iconColor('danger')
                    ->columnSpanFull()
                    ->visible(fn (User $record) => ! $record->is_active)
                    ->schema([TextEntry::make('suspension_reason')->hiddenLabel()->placeholder(__('No reason recorded.'))]),
                Section::make(__('Account'))
                    ->columnSpan(2)
                    ->columns(3)
                    ->schema([
                        TextEntry::make('email')->label(__('Email'))->copyable(),
                        IconEntry::make('verified')->label(__('Email verified'))->boolean()
                            ->state(fn (User $record) => $record->email_verified_at !== null),
                        TextEntry::make('phone')->label(__('Phone'))->placeholder('—'),
                        TextEntry::make('address')->label(__('Address'))->placeholder('—')->columnSpan(2),
                        TextEntry::make('kyc_status')->label(__('Identity'))->badge()
                            ->formatStateUsing(fn (KycStatus $state) => $state->label())->color(fn (KycStatus $state) => $state->color()),
                        TextEntry::make('timezone')->label(__('Timezone'))->placeholder(__('Default')),
                        TextEntry::make('created_at')->label(__('Joined'))->dateTime('j M Y'),
                        TextEntry::make('last_login_at')->label(__('Last sign-in'))->dateTime('j M Y, H:i')->placeholder(__('Never')),
                    ]),
                Section::make(__('Wallet'))
                    ->columnSpan(1)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('wallet.balance')->label(__('Balance'))->formatStateUsing($money)->placeholder('—'),
                        TextEntry::make('wallet.held')->label(__('On hold'))->formatStateUsing($money)->placeholder('—'),
                        TextEntry::make('bids_count')->label(__('Bids placed')),
                        TextEntry::make('orders_count')->label(__('Orders')),
                        TextEntry::make('active_strikes')->label(__('Active strikes'))
                            ->state(fn (User $record) => $record->strikes()->active()->count().' / '.config('auction.trust.strike_limit')),
                    ]),
                KycRequestResource::documentsSection(),
            ]);
    }

    public static function getRelations(): array
    {
        // One group renders the tables one under the other instead of as tabs.
        return [RelationGroup::make(__('Related'), [OrdersRelationManager::class, StrikesRelationManager::class, LoginEventsRelationManager::class, KycRequestsRelationManager::class])];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBuyers::route('/'),
            'view' => ViewBuyer::route('/{record}'),
        ];
    }
}
