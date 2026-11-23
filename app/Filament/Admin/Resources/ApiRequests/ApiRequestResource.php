<?php

namespace App\Filament\Admin\Resources\ApiRequests;

use App\Filament\Admin\Resources\ApiRequests\Pages\ListApiRequests;
use App\Models\ApiRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every authenticated API request, newest first: who, with which token, which
 * endpoint and how it ended. Read only; kept for auction.api.log_days.
 */
class ApiRequestResource extends Resource
{
    protected static ?string $model = ApiRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 32;

    protected static ?string $slug = 'api-activity';

    protected static ?string $modelLabel = 'API request';

    public static function getNavigationGroup(): string
    {
        return __('System');
    }

    public static function getNavigationLabel(): string
    {
        return __('API activity');
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
        return parent::getEloquentQuery()->with('user:id,name,email');
    }

    /** @return list<TextColumn> */
    public static function columns(bool $withUser = true): array
    {
        return array_values(array_filter([
            TextColumn::make('created_at')->label(__('When'))->dateTime('j M Y, H:i:s')->sortable(),
            $withUser ? TextColumn::make('user.name')->label(__('Account'))->description(fn (ApiRequest $record) => $record->user?->email)->placeholder('—')->searchable() : null,
            $withUser ? TextColumn::make('token_name')->label(__('Token'))->placeholder('—')->searchable() : null,
            TextColumn::make('method')->label(__('Method'))->badge()->color('gray'),
            TextColumn::make('route')->label(__('Endpoint'))->formatStateUsing(fn (string $state) => '/'.$state)->searchable(),
            TextColumn::make('status')->label(__('Status'))->badge()
                ->color(fn (int $state) => match (true) {
                    $state < 300 => 'success',
                    $state < 400 => 'info',
                    $state < 500 => 'warning',
                    default => 'danger',
                })
                ->sortable(),
            TextColumn::make('duration_ms')->label(__('Time'))->suffix(' ms')->sortable()->toggleable(),
            TextColumn::make('ip_address')->label(__('IP address'))->copyable()->searchable()->toggleable(),
        ]));
    }

    /** @return list<SelectFilter|Filter> */
    public static function filters(): array
    {
        return [
            SelectFilter::make('result')->label(__('Result'))
                ->options(['ok' => __('Succeeded (2xx)'), 'client' => __('Refused (4xx)'), 'server' => __('Server error (5xx)')])
                ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                    'ok' => $query->where('status', '<', 400),
                    'client' => $query->whereBetween('status', [400, 499]),
                    'server' => $query->where('status', '>=', 500),
                    default => $query,
                }),
            SelectFilter::make('method')->label(__('Method'))->options(['GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE']),
            Filter::make('today')->label(__('Last 24 hours'))->query(fn (Builder $query) => $query->where('created_at', '>=', now()->subDay())),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns(self::columns())
            ->filters(self::filters())
            ->emptyStateHeading(__('No API requests yet'));
    }

    public static function getPages(): array
    {
        return ['index' => ListApiRequests::route('/')];
    }
}
