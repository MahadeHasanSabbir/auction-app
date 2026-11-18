<?php

namespace App\Filament\Admin\Resources\Tickets\Pages;

use App\Enums\ConversationStatus;
use App\Filament\Admin\Resources\Tickets\TicketResource;
use App\Models\Conversation;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListTickets extends ListRecords
{
    protected static string $resource = TicketResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'waiting' => Tab::make(__('Not taken'))
                ->modifyQueryUsing(fn (Builder $query) => TicketResource::unassigned($query))
                ->badge(fn () => TicketResource::unassigned(Conversation::query())->count() ?: null)
                ->badgeColor('warning'),
            'mine' => Tab::make(__('Mine'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ConversationStatus::Open)->where('assigned_to', Auth::id())),
            'open' => Tab::make(__('All open'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ConversationStatus::Open)),
            'closed' => Tab::make(__('Closed'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ConversationStatus::Closed)),
            'all' => Tab::make(__('All')),
        ];
    }
}
