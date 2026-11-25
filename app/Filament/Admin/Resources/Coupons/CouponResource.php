<?php

namespace App\Filament\Admin\Resources\Coupons;

use App\Enums\CouponTarget;
use App\Filament\Admin\Resources\Coupons\Pages\ManageCoupons;
use App\Filament\Support\CouponForm;
use App\Models\Coupon;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The platform's own coupons: off plan invoices and the featured-listing
 * fee, or wallet bonus credit for buyers. Sellers keep their order coupons
 * in their own panel.
 */
class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?int $navigationSort = 25;

    public static function getNavigationGroup(): string
    {
        return __('Marketplace');
    }

    public static function getNavigationLabel(): string
    {
        return __('Coupons');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('company_id');
    }

    public static function form(Schema $schema): Schema
    {
        return CouponForm::schema($schema, platform: true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns(CouponForm::columns(platform: true))
            ->filters([
                SelectFilter::make('target')->label(__('For'))
                    ->options(collect(CouponTarget::platform())->mapWithKeys(fn (CouponTarget $target) => [$target->value => $target->label()])->all()),
            ])
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
