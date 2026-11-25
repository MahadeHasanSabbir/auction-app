<?php

namespace App\Filament\Seller\Resources\Coupons\Pages;

use App\Filament\Seller\Resources\Coupons\CouponResource;
use App\Filament\Support\CouponForm;
use App\Models\Company;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

class ManageCoupons extends ManageRecords
{
    protected static string $resource = CouponResource::class;

    protected function getHeaderActions(): array
    {
        $company = Filament::getTenant();

        return [CouponForm::create($company instanceof Company ? $company : null)];
    }
}
