<?php

namespace App\Filament\Admin\Resources\Pages;

use App\Filament\Admin\Resources\Pages\Pages\EditPage;
use App\Filament\Admin\Resources\Pages\Pages\ListPages;
use App\Models\Page;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The site's text pages (terms, privacy, FAQ, about), in English and
 * Bangla. The set is fixed (SitePage); staff only edit. UpdatePage
 * sanitises the HTML before it is stored.
 */
class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 26;

    protected static ?string $slug = 'pages';

    public static function getNavigationGroup(): string
    {
        return __('Marketplace');
    }

    public static function getNavigationLabel(): string
    {
        return __('Site pages');
    }

    public static function getModelLabel(): string
    {
        return __('page');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('editor:id,name');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Tabs::make()->tabs([
                    Tab::make(__('English'))->schema([
                        TextInput::make('title_en')->label(__('Title'))->required()->minLength(2)->maxLength(255),
                        self::editor('body_en'),
                    ]),
                    Tab::make(__('Bangla'))->schema([
                        TextInput::make('title_bn')->label(__('Title'))->maxLength(255)
                            ->helperText(__('Leave empty to show the English page to Bangla readers.')),
                        self::editor('body_bn'),
                    ]),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->paginated(false)
            ->columns([
                TextColumn::make('title_en')->label(__('Page'))->weight('medium')
                    ->description(fn (Page $record) => $record->slug->label()),
                TextColumn::make('title_bn')->label(__('Bangla'))
                    ->state(fn (Page $record) => filled($record->body_bn) ? __('Translated') : __('English only'))
                    ->badge()
                    ->color(fn (Page $record) => filled($record->body_bn) ? 'success' : 'gray'),
                TextColumn::make('updated_at')->label(__('Last changed'))->dateTime('j M Y, H:i')->sortable(),
                TextColumn::make('editor.name')->label(__('By'))->placeholder('—'),
            ])
            ->recordActions([
                Action::make('open')->label(__('Open'))->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                    ->url(fn (Page $record) => route($record->slug->routeName()), shouldOpenInNewTab: true),
                EditAction::make(),
            ])
            ->emptyStateHeading(__('No pages yet'))
            ->emptyStateDescription(__('Run the platform seeder to create them.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPages::route('/'),
            'edit' => EditPage::route('/{record}/edit'),
        ];
    }

    private static function editor(string $field): RichEditor
    {
        return RichEditor::make($field)->label(__('Text'))
            ->fileAttachments(false)
            ->toolbarButtons([
                ['bold', 'italic', 'underline', 'link'],
                ['h2', 'h3'],
                ['blockquote', 'bulletList', 'orderedList', 'table'],
                ['undo', 'redo'],
            ]);
    }
}
