<?php

namespace App\Filament\Admin\Resources\Coupons\Pages;

use App\Filament\Admin\Resources\Coupons\CouponResource;
use App\Filament\Support\CouponForm;
use Filament\Resources\Pages\ManageRecords;

class ManageCoupons extends ManageRecords
{
    protected static string $resource = CouponResource::class;

    protected function getHeaderActions(): array
    {
        return [CouponForm::create(null)];
    }
}
