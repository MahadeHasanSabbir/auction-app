<?php

namespace App\Filament\Seller\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Seller\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tab = fn (string $label, array $statuses) => Tab::make($label)
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', $statuses));

        return [
            'ship' => $tab(__('To ship'), [OrderStatus::InEscrow]),
            'shipped' => $tab(__('Shipped'), [OrderStatus::Shipped]),
            'disputed' => $tab(__('Disputed'), [OrderStatus::Disputed]),
            'completed' => $tab(__('Completed'), [OrderStatus::Completed]),
            'closed' => $tab(__('Refunded or cancelled'), [OrderStatus::Refunded, OrderStatus::PartiallyRefunded, OrderStatus::Cancelled]),
            'all' => Tab::make(__('All')),
        ];
    }
}
