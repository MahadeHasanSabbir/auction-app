<?php

namespace App\Filament\Admin\Resources\ApiTokens;

use App\Actions\Api\RevokeApiToken;
use App\Filament\Admin\Resources\ApiTokens\Pages\ListApiTokens;
use App\Filament\Admin\Resources\ApiTokens\Pages\ViewApiToken;
use App\Filament\Admin\Resources\ApiTokens\RelationManagers\RequestsRelationManager;
use App\Filament\Support\Outcome;
use App\Models\ApiToken;
use App\Models\Company;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;

/**
 * Who has an API token: the account, its company, what the token may do, when
 * it was last used and how much. Staff can revoke a token (with a reason).
 * The token value itself is stored only as a hash, so it is never shown.
 */
class ApiTokenResource extends Resource
{
    protected static ?string $model = ApiToken::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?int $navigationSort = 31;

    protected static ?string $slug = 'api-access';

    protected static ?string $modelLabel = 'API token';

    public static function getNavigationGroup(): string
    {
        return __('System');
    }

    public static function getNavigationLabel(): string
    {
        return __('API access');
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
            ->with(['tokenable' => function (Relation $relation): void {
                if ($relation instanceof MorphTo) {
                    $relation->morphWith([User::class => ['company:id,name']]);
                }
            }])
            ->withCount('requests')
            ->withMax('requests', 'created_at');
    }

    public static function owner(ApiToken $token): ?User
    {
        $owner = $token->tokenable;

        return $owner instanceof User ? $owner : null;
    }

    public static function status(ApiToken $token): string
    {
        return match (true) {
            $token->isExpired() => __('Expired'),
            $token->last_used_at === null => __('Never used'),
            default => __('Active'),
        };
    }

    public static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label(__('Revoke'))
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->modalDescription(__('The app using this token gets an error from its next request. The account holder can make a new token.'))
            ->schema([
                Textarea::make('reason')->label(__('Reason'))->required()->minLength(3)->maxLength(500)->helperText(__('Kept in the activity log; not shown to the person.')),
            ])
            ->visible(fn (ApiToken $record) => Gate::allows('revoke', $record))
            ->action(Outcome::of(fn (ApiToken $record, array $data) => app(RevokeApiToken::class)->handle(Outcome::user(), $record, (string) $data['reason']), __('Token revoked.')));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_used_at', 'desc')
            ->columns([
                TextColumn::make('owner')->label(__('Account'))
                    ->state(fn (ApiToken $record) => self::owner($record)->name ?? __('Closed account'))
                    ->description(fn (ApiToken $record) => self::owner($record)?->email)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $like = '%'.addcslashes($search, '\\%_').'%';

                        return $query->whereHasMorph('tokenable', [User::class], fn (Builder $user) => $user->where('name', 'like', $like)->orWhere('email', 'like', $like));
                    }),
                TextColumn::make('side')->label(__('Works as'))
                    ->state(fn (ApiToken $record) => ($owner = self::owner($record)) === null ? '—' : ($owner->company->name ?? __('Buyer')))
                    ->description(fn (ApiToken $record) => self::owner($record)?->role->value),
                TextColumn::make('name')->label(__('Token'))->searchable(),
                TextColumn::make('access')->label(__('Access'))->badge()
                    ->state(fn (ApiToken $record) => $record->canWrite() ? __('Read and write') : __('Read only'))
                    ->color(fn (ApiToken $record) => $record->canWrite() ? 'warning' : 'gray'),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->state(fn (ApiToken $record) => self::status($record))
                    ->color(fn (string $state) => match ($state) {
                        __('Active') => 'success',
                        __('Expired') => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('last_used_at')->label(__('Last used'))->dateTime('j M Y, H:i')->placeholder(__('never'))->sortable(),
                TextColumn::make('requests_count')->label(__('Requests'))->numeric()->sortable(),
                TextColumn::make('created_at')->label(__('Created'))->dateTime('j M Y')->sortable()->toggleable(),
                TextColumn::make('expires_at')->label(__('Expires'))->dateTime('j M Y')->placeholder(__('never'))->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))
                    ->options(['active' => __('Active'), 'expired' => __('Expired'), 'unused' => __('Never used')])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'active' => $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())),
                        'expired' => $query->where('expires_at', '<=', now()),
                        'unused' => $query->whereNull('last_used_at'),
                        default => $query,
                    }),
                SelectFilter::make('access')->label(__('Access'))
                    ->options(['write' => __('Read and write'), 'read' => __('Read only')])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'write' => $query->where('abilities', 'like', '%"write"%'),
                        'read' => $query->where('abilities', 'not like', '%"write"%'),
                        default => $query,
                    }),
                SelectFilter::make('company')->label(__('Company'))
                    ->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHasMorph('tokenable', [User::class], fn (Builder $user) => $user->where('company_id', $data['value']))
                        : $query),
            ])
            ->recordActions([ViewAction::make(), self::revokeAction()])
            ->emptyStateHeading(__('Nobody has made an API token yet'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                TextEntry::make('owner')->label(__('Account'))->state(fn (ApiToken $record) => self::owner($record)->name ?? __('Closed account')),
                TextEntry::make('email')->label(__('Email'))->state(fn (ApiToken $record) => self::owner($record)?->email)->placeholder('—'),
                TextEntry::make('side')->label(__('Works as'))->state(fn (ApiToken $record) => self::owner($record)->company->name ?? __('Buyer')),
                TextEntry::make('name')->label(__('Token name')),
                TextEntry::make('access')->label(__('Access'))->state(fn (ApiToken $record) => $record->canWrite() ? __('Read and write') : __('Read only')),
                TextEntry::make('status')->label(__('Status'))->state(fn (ApiToken $record) => self::status($record)),
                TextEntry::make('created_at')->label(__('Created'))->dateTime('j M Y, H:i'),
                TextEntry::make('last_used_at')->label(__('Last used'))->dateTime('j M Y, H:i')->placeholder(__('never')),
                TextEntry::make('expires_at')->label(__('Expires'))->dateTime('j M Y, H:i')->placeholder(__('never')),
                TextEntry::make('requests_count')->label(__('Requests in the log')),
                TextEntry::make('requests_max_created_at')->label(__('Latest request'))->dateTime('j M Y, H:i:s')->placeholder('—'),
            ]);
    }

    public static function getRelations(): array
    {
        return [RequestsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApiTokens::route('/'),
            'view' => ViewApiToken::route('/{record}'),
        ];
    }
}
