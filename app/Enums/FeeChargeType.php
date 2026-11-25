<?php

namespace App\Enums;

/** Who a charge is collected for. Each type is posted to its own ledger account. */
enum FeeChargeType: string
{
    /** Passed on to the payment gateway. */
    case Gateway = 'gateway';

    /** Platform service charge. */
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Gateway => __('Gateway charge'),
            self::Service => __('Service charge'),
        };
    }
}
