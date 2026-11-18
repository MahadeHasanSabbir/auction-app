<?php

namespace App\Filament\Admin\Resources\Activity;

use App\Enums\UserRole;
use App\Filament\Admin\Resources\Activity\Pages\ListActivities;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Spatie\Activitylog\Models\Activity;

/**
 * Who did what: staff decisions about money, companies, accounts and
 * auctions, as written by the actions to the activity log. Read only, for
 * the system owner (ActivityPolicy).
 */
class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'activity';

    protected static ?string $modelLabel = 'activity';

    public static function getNavigationGroup(): string
    {
        return __('System');
    }

    public static function getNavigationLabel(): string
    {
        return __('Activity log');
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
        return parent::getEloquentQuery()->with('causer');
    }

    /** "user 12", "order 40": the subject as its short morph name and id. */
    public static function subject(Activity $activity): ?string
    {
        return $activity->subject_type !== null ? "{$activity->subject_type} {$activity->subject_id}" : null;
    }

    public static function causer(Activity $activity): string
    {
        $causer = $activity->causer;

        return $causer instanceof Model ? (string) $causer->getAttribute('name') : __('System');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('When'))->dateTime('j M Y, H:i:s')->sortable(),
                TextColumn::make('log_name')->label(__('Log'))->badge()->color('gray'),
                TextColumn::make('event')->label(__('Event'))->placeholder('—'),
                TextColumn::make('description')->label(__('What'))->wrap()->searchable(),
                TextColumn::make('causer_name')->label(__('By'))->state(fn (Activity $record) => self::causer($record)),
                TextColumn::make('subject')->label(__('On'))->state(fn (Activity $record) => self::subject($record))->placeholder('—')->color('gray'),
            ])
            ->filters([
                SelectFilter::make('log_name')->label(__('Log'))
                    ->options(fn () => Activity::query()->distinct()->orderBy('log_name')->pluck('log_name', 'log_name')->filter()->all()),
                SelectFilter::make('causer_id')->label(__('By'))
                    ->options(fn () => User::query()->whereIn('role', UserRole::platform())->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->where('causer_type', 'user')->where('causer_id', $data['value'])
                        : $query),
                Filter::make('subject')
                    ->schema([
                        TextInput::make('subject_type')->label(__('Subject kind'))->placeholder('order, user, company…'),
                        TextInput::make('subject_id')->label(__('Subject id'))->integer(),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when(filled($data['subject_type'] ?? null), fn (Builder $q) => $q->where('subject_type', $data['subject_type']))
                        ->when(filled($data['subject_id'] ?? null), fn (Builder $q) => $q->where('subject_id', $data['subject_id']))),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextEntry::make('description')->label(__('What'))->columnSpanFull(),
                TextEntry::make('created_at')->label(__('When'))->dateTime('j M Y, H:i:s'),
                TextEntry::make('causer_name')->label(__('By'))->state(fn (Activity $record) => self::causer($record)),
                TextEntry::make('log_name')->label(__('Log')),
                TextEntry::make('subject')->label(__('On'))->state(fn (Activity $record) => self::subject($record))->placeholder('—'),
                TextEntry::make('properties')->label(__('Details'))->columnSpanFull()
                    ->state(fn (Activity $record) => new HtmlString('<pre class="whitespace-pre-wrap text-xs">'.e((string) json_encode($record->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)).'</pre>')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
        ];
    }
}
