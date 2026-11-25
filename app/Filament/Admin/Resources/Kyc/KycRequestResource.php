<?php

namespace App\Filament\Admin\Resources\Kyc;

use App\Enums\KycStatus;
use App\Filament\Admin\Resources\Buyers\BuyerResource;
use App\Filament\Admin\Resources\Companies\CompanyResource;
use App\Filament\Admin\Resources\Kyc\Pages\ListKycRequests;
use App\Filament\Admin\Resources\Kyc\Pages\ViewKycRequest;
use App\Http\Controllers\KycDocumentController;
use App\Models\Company;
use App\Models\KycDocument;
use App\Models\KycRequest;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

/**
 * The verification desk: identity documents from buyers and companies.
 * Files are opened through short-lived signed links, never directly.
 */
class KycRequestResource extends Resource
{
    protected static ?string $model = KycRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 7;

    protected static ?string $modelLabel = 'verification';

    protected static ?string $slug = 'verifications';

    public static function getNavigationGroup(): string
    {
        return __('Desk');
    }

    public static function getNavigationLabel(): string
    {
        return __('Verifications');
    }

    public static function getNavigationBadge(): ?string
    {
        $open = KycRequest::query()->where('status', KycStatus::Submitted)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return __('Documents to check');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user:id,name,email', 'company:id,name,slug', 'requester:id,name', 'reviewer:id,name', 'documents']);
    }

    public static function subject(KycRequest $record): string
    {
        return $record->company->name ?? $record->user->name ?? '—';
    }

    public static function subjectUrl(KycRequest $record): ?string
    {
        return match (true) {
            // Company pages are the system owner's; staff see the request only.
            $record->company !== null => Gate::allows('view', $record->company) ? CompanyResource::getUrl('view', ['record' => $record->company]) : null,
            $record->user !== null => BuyerResource::getUrl('view', ['record' => $record->user]),
            default => null,
        };
    }

    public static function status(TextColumn|TextEntry $component): TextColumn|TextEntry
    {
        return $component->badge()
            ->formatStateUsing(fn (KycStatus $state) => $state->label())
            ->color(fn (KycStatus $state) => $state->color());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('subject')->label(__('Who'))
                    ->state(fn (KycRequest $record) => self::subject($record))
                    ->description(fn (KycRequest $record) => $record->company_id !== null ? __('Company') : __('Buyer')),
                TextColumn::make('documents_count')->label(__('Files'))->counts('documents')->alignEnd(),
                self::status(TextColumn::make('status')->label(__('Status'))),
                TextColumn::make('submitted_at')->label(__('Sent'))->dateTime('j M Y, H:i')->sortable()->placeholder('—'),
                TextColumn::make('reviewer.name')->label(__('Reviewed by'))->placeholder('—')->toggleable(),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([KycActions::approve(), KycActions::reject()]),
            ])
            ->emptyStateHeading(__('Nothing here'));
    }

    public static function infolist(Schema $schema): Schema
    {
        $date = 'j M Y, H:i';

        return $schema
            ->columns(3)
            ->components([
                Section::make(__('Request'))
                    ->columnSpan(2)
                    ->columns(3)
                    ->schema([
                        TextEntry::make('subject')->label(__('Who'))
                            ->state(fn (KycRequest $record) => self::subject($record))
                            ->url(fn (KycRequest $record) => self::subjectUrl($record)),
                        TextEntry::make('kind')->label(__('Account'))
                            ->state(fn (KycRequest $record) => $record->company_id !== null ? __('Company') : __('Buyer')),
                        self::status(TextEntry::make('status')->label(__('Status'))),
                        TextEntry::make('requester.name')->label(__('Asked by')),
                        TextEntry::make('created_at')->label(__('Asked'))->dateTime($date),
                        TextEntry::make('submitted_at')->label(__('Sent'))->dateTime($date)->placeholder('—'),
                        TextEntry::make('reviewer.name')->label(__('Reviewed by'))->placeholder('—'),
                        TextEntry::make('reviewed_at')->label(__('Reviewed'))->dateTime($date)->placeholder('—'),
                        TextEntry::make('note')->label(__('Note to them'))->placeholder('—'),
                        TextEntry::make('rejection_reason')->label(__('Reason for rejecting'))->columnSpanFull()
                            ->visible(fn (KycRequest $record) => $record->status === KycStatus::Rejected),
                    ]),
                Section::make(__('Documents'))
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('files')->hiddenLabel()
                            ->state(fn (KycRequest $record) => self::documentLinks($record))
                            ->placeholder(__('No files yet.')),
                    ]),
            ]);
    }

    /**
     * The newest verification of a buyer or company with its files, for
     * their admin pages; older requests are on the Verifications desk.
     */
    public static function documentsSection(): Section
    {
        $latest = fn (User|Company $record): ?KycRequest => KycRequest::query()
            ->where($record instanceof Company ? 'company_id' : 'user_id', $record->getKey())
            ->with(['documents', 'reviewer:id,name'])
            ->latest('id')
            ->first();

        return Section::make(__('Identity documents'))
            ->description(__('The latest verification. Earlier ones are under Verification history.'))
            ->icon(Heroicon::OutlinedIdentification)
            ->columnSpanFull()
            ->columns(4)
            ->schema([
                TextEntry::make('kyc_status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (KycStatus $state) => $state->label())
                    ->color(fn (KycStatus $state) => $state->color()),
                TextEntry::make('kyc_sent')->label(__('Sent'))->dateTime('j M Y, H:i')->placeholder('—')
                    ->state(fn (User|Company $record) => $latest($record)?->submitted_at),
                TextEntry::make('kyc_reviewed')->label(__('Reviewed'))->placeholder('—')
                    ->state(fn (User|Company $record) => ($request = $latest($record))?->reviewed_at !== null
                        ? $request->reviewed_at->timezone(config('auction.display_timezone'))->format('j M Y, H:i').' · '.($request->reviewer->name ?? '—')
                        : null),
                TextEntry::make('kyc_request')->label(__('Request'))->placeholder('—')
                    ->state(fn (User|Company $record) => ($request = $latest($record)) !== null ? __('Verification #:id', ['id' => $request->id]) : null)
                    ->url(fn (User|Company $record) => ($request = $latest($record)) !== null ? self::getUrl('view', ['record' => $request]) : null),
                TextEntry::make('kyc_reason')->label(__('Reason for rejecting'))->columnSpanFull()
                    ->state(fn (User|Company $record) => $latest($record)?->rejection_reason)
                    ->visible(fn (User|Company $record) => $record->kyc_status === KycStatus::Rejected),
                TextEntry::make('kyc_files')->label(__('Files'))->columnSpanFull()
                    ->state(fn (User|Company $record) => ($request = $latest($record)) !== null ? self::documentLinks($request) : null)
                    ->placeholder(__('No documents uploaded.')),
            ]);
    }

    /** One signed link per file, valid for a few minutes. */
    private static function documentLinks(KycRequest $record): ?HtmlString
    {
        if ($record->documents->isEmpty()) {
            return null;
        }

        return new HtmlString($record->documents->map(fn (KycDocument $document) => sprintf(
            '<a href="%s" target="_blank" rel="noopener" class="block py-1 font-medium text-primary-600 hover:underline dark:text-primary-400">%s</a><span class="block text-xs text-gray-500">%s · %s KB</span>',
            e(KycDocumentController::link($document)),
            e($document->type->label()),
            e($document->original_name),
            number_format((int) ceil($document->size / 1024)),
        ))->implode(''));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKycRequests::route('/'),
            'view' => ViewKycRequest::route('/{record}'),
        ];
    }
}
