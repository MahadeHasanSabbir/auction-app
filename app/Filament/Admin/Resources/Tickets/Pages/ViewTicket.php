<?php

namespace App\Filament\Admin\Resources\Tickets\Pages;

use App\Actions\Support\AssignTicket;
use App\Actions\Support\CloseTicket;
use App\Enums\ConversationStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Tickets\TicketResource;
use App\Filament\Support\Outcome;
use App\Models\Conversation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\On;

class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    public function getTitle(): string
    {
        return (string) $this->getRecord()->getAttribute('subject');
    }

    public function getSubheading(): string
    {
        return __('Ticket #:id', ['id' => $this->getRecord()->getKey()]);
    }

    /** A reply can reopen the ticket; show its new state. */
    #[On('ticket-updated')]
    public function refreshTicket(): void
    {
        $this->getRecord()->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('take')
                ->label(__('Take it'))
                ->icon('heroicon-o-hand-raised')
                ->visible(fn (Conversation $record) => $record->assigned_to !== Outcome::user()->id)
                ->action(Outcome::of(fn (Conversation $record) => app(AssignTicket::class)->handle(Outcome::user(), $record, Outcome::user()), __('The ticket is yours.'))),
            Action::make('assign')
                ->label(__('Hand over'))
                ->icon('heroicon-o-arrows-right-left')
                ->color('gray')
                ->schema([
                    Select::make('assignee')
                        ->label(__('Give it to'))
                        ->options(fn () => User::query()->whereIn('role', UserRole::platform())->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->placeholder(__('Nobody'))
                        ->default(fn (Conversation $record) => $record->assigned_to),
                ])
                ->action(Outcome::of(
                    fn (Conversation $record, array $data) => app(AssignTicket::class)->handle(
                        Outcome::user(),
                        $record,
                        filled($data['assignee'] ?? null) ? User::query()->findOrFail($data['assignee']) : null,
                    ),
                    __('Saved.'),
                )),
            Action::make('close')
                ->label(__('Close'))
                ->icon('heroicon-o-check-circle')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('A new message from the requester reopens it.'))
                ->visible(fn (Conversation $record) => $record->status === ConversationStatus::Open)
                ->action(Outcome::of(fn (Conversation $record) => app(CloseTicket::class)->handle(Outcome::user(), $record), __('Ticket closed.'))),
        ];
    }
}
