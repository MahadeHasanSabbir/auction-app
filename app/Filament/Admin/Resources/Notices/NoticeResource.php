<?php

namespace App\Filament\Admin\Resources\Notices;

use App\Enums\NoticeAudience;
use App\Filament\Admin\Resources\Notices\Pages\ManageNotices;
use App\Models\Notice;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Announcements on the site and in the seller panel, for everyone, buyers
 * or sellers. The newest current one shows as a banner; /notices lists all.
 */
class NoticeResource extends Resource
{
    protected static ?string $model = Notice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 24;

    public static function getNavigationGroup(): string
    {
        return __('Marketplace');
    }

    public static function getNavigationLabel(): string
    {
        return __('Notices');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('creator:id,name');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('title')->label(__('Title'))->required()->maxLength(190)->columnSpanFull(),
                Textarea::make('body')->label(__('Text'))->required()->rows(4)->maxLength(5000)->columnSpanFull(),
                Select::make('audience')->label(__('Shown to'))
                    ->options(collect(NoticeAudience::cases())->mapWithKeys(fn (NoticeAudience $audience) => [$audience->value => $audience->label()])->all())
                    ->default(NoticeAudience::All->value)
                    ->required()
                    ->selectablePlaceholder(false),
                DateTimePicker::make('published_at')->label(__('Publish at'))->seconds(false)
                    ->helperText(__('Leave empty to keep it as a draft.')),
                DateTimePicker::make('expires_at')->label(__('Take down at'))->seconds(false)
                    ->after('published_at')
                    ->helperText(__('Optional.')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('title')->label(__('Title'))->searchable()->weight('medium')
                    ->description(fn (Notice $record) => str($record->body)->limit(80)->toString()),
                TextColumn::make('audience')->label(__('Shown to'))->badge()
                    ->formatStateUsing(fn (NoticeAudience $state) => $state->label()),
                TextColumn::make('state')->label(__('State'))->badge()
                    ->state(fn (Notice $record) => match (true) {
                        $record->published_at === null => __('Draft'),
                        $record->published_at->isFuture() => __('Scheduled'),
                        $record->expires_at !== null && $record->expires_at->isPast() => __('Ended'),
                        default => __('Showing'),
                    })
                    ->color(fn (string $state) => $state === __('Showing') ? 'success' : ($state === __('Scheduled') ? 'info' : 'gray')),
                TextColumn::make('published_at')->label(__('Published'))->dateTime('j M Y, H:i')->sortable()->placeholder('—'),
                TextColumn::make('expires_at')->label(__('Ends'))->dateTime('j M Y, H:i')->placeholder('—'),
                TextColumn::make('creator.name')->label(__('By'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('audience')->label(__('Shown to'))
                    ->options(collect(NoticeAudience::cases())->mapWithKeys(fn (NoticeAudience $audience) => [$audience->value => $audience->label()])->all()),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading(__('No notices yet'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageNotices::route('/'),
        ];
    }
}
