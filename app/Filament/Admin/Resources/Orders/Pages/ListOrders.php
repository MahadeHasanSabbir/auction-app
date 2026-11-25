<?php

namespace App\Filament\Admin\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tab = fn (string $label, OrderStatus ...$statuses) => Tab::make($label)
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', $statuses));

        return [
            'all' => Tab::make(__('All')),
            'escrow' => $tab(__('To ship'), OrderStatus::InEscrow),
            'shipped' => $tab(__('Shipped'), OrderStatus::Shipped),
            'disputed' => $tab(__('Disputed'), OrderStatus::Disputed)
                ->badge(fn () => Order::query()->where('status', OrderStatus::Disputed)->count() ?: null)
                ->badgeColor('danger'),
            'done' => $tab(__('Finished'), OrderStatus::Completed, OrderStatus::Refunded, OrderStatus::PartiallyRefunded, OrderStatus::Cancelled),
        ];
    }
}
