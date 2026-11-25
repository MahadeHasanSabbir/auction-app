<?php

namespace App\Enums;

enum ShipmentStatus: string
{
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::InTransit => __('In transit'),
            self::Delivered => __('Delivered'),
            self::Returned => __('Returned'),
        };
    }
}
