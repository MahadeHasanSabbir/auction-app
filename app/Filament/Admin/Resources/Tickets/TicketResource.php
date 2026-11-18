<?php

namespace App\Filament\Admin\Resources\Tickets;

use App\Enums\ConversationStatus;
use App\Enums\ConversationType;
use App\Filament\Admin\Resources\Tickets\Pages\ListTickets;
use App\Filament\Admin\Resources\Tickets\Pages\ViewTicket;
use App\Livewire\Support\StaffThread;
use App\Models\Conversation;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The support desk: tickets from signed-in people and from the contact
 * form. Staff take a ticket, answer it (the requester is told by bell and
 * email, a visitor by email) and close it. Calls the actions in
 * App\Actions\Support.
 */
class TicketResource extends Resource
{
    protected static ?string $model = Conversation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?int $navigationSort = 6;

    protected static ?string $modelLabel = 'ticket';

    protected static ?string $slug = 'tickets';

    public static function getNavigationGroup(): string
    {
        return __('Desk');
    }

    public static function getNavigationLabel(): string
    {
        return __('Support tickets');
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = self::unassigned(Conversation::query())->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return __('Open and not taken');
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public static function unassigned(Builder $query): Builder
    {
        return $query->where('type', ConversationType::Support)->where('status', ConversationStatus::Open)->whereNull('assigned_to');
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
            ->where('type', ConversationType::Support)
            ->with(['opener:id,name,email', 'assignee:id,name']);
    }

    /** Who wrote in: the account, or the visitor's name and address. */
    public static function requester(Conversation $ticket): string
    {
        return $ticket->opener->name ?? (string) $ticket->guest_name;
    }

    public static function requesterContact(Conversation $ticket): string
    {
        return $ticket->opener !== null ? $ticket->opener->email : __(':email (visitor)', ['email' => $ticket->guest_email]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_message_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('subject')->label(__('Subject'))->searchable()->weight('medium')->wrap(),
                TextColumn::make('requester')->label(__('From'))
                    ->state(fn (Conversation $record) => self::requester($record))
                    ->description(fn (Conversation $record) => self::requesterContact($record)),
                TextColumn::make('assignee.name')->label(__('Taken by'))->placeholder(__('Nobody')),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (ConversationStatus $state) => $state->label())
                    ->color(fn (ConversationStatus $state) => $state === ConversationStatus::Open ? 'success' : 'gray'),
                TextColumn::make('last_message_at')->label(__('Last message'))->since()->sortable(),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading(__('No tickets here'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make(__('Conversation'))
                    ->columnSpan(2)
                    ->schema([
                        Livewire::make(StaffThread::class, fn (Conversation $record) => ['ticketId' => $record->id])
                            ->key(fn (Conversation $record) => "ticket-thread-{$record->id}"),
                    ]),
                Section::make(__('Ticket'))
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('status')->label(__('Status'))->badge()
                            ->formatStateUsing(fn (ConversationStatus $state) => $state->label())
                            ->color(fn (ConversationStatus $state) => $state === ConversationStatus::Open ? 'success' : 'gray'),
                        TextEntry::make('requester')->label(__('From'))
                            ->state(fn (Conversation $record) => self::requester($record))
                            ->helperText(fn (Conversation $record) => self::requesterContact($record)),
                        TextEntry::make('assignee.name')->label(__('Taken by'))->placeholder(__('Nobody')),
                        TextEntry::make('created_at')->label(__('Opened'))->dateTime('j M Y, H:i'),
                        TextEntry::make('closed_at')->label(__('Closed'))->dateTime('j M Y, H:i')->placeholder('—'),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTickets::route('/'),
            'view' => ViewTicket::route('/{record}'),
        ];
    }
}
