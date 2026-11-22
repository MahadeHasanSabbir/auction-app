<?php

namespace App\Filament\Seller\Resources\Coupons;

use App\Filament\Seller\Resources\Coupons\Pages\ManageCoupons;
use App\Filament\Support\CouponForm;
use App\Models\Company;
use App\Models\Coupon;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The company's coupon codes for buyers: a buyer enters one on a won order
 * before it ships and gets the discount back from escrow, paid by the
 * company. The owner manages them; the team can look.
 */
class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?int $navigationSort = 7;

    public static function getNavigationLabel(): string
    {
        return __('Coupons');
    }

    /** The owner makes the company's coupons. */
    public static function canCreate(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company && (Auth::user()?->can('create', [Coupon::class, $tenant]) ?? false);
    }

    /** Scoped to the company here as well as by the panel's tenancy scope. */
    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        return parent::getEloquentQuery()->where('company_id', $tenant instanceof Company ? $tenant->id : 0);
    }

    public static function form(Schema $schema): Schema
    {
        return CouponForm::schema($schema, platform: false);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->description(__('Buyers enter a code on a won order before you ship it. The discount comes out of your payout; the platform commission stays on the full price.'))
            ->columns(CouponForm::columns(platform: false))
            ->recordActions([CouponForm::edit(), DeleteAction::make()])
            ->emptyStateHeading(__('No coupons yet'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCoupons::route('/'),
        ];
    }
}
