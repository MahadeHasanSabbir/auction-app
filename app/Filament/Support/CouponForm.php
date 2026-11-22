<?php

namespace App\Filament\Support;

use App\Actions\Coupons\SaveCoupon;
use App\Enums\CouponDiscountType;
use App\Enums\CouponTarget;
use App\Models\Company;
use App\Models\Coupon;
use App\Support\Money;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Validation\ValidationException;

/**
 * The coupon form, table columns and create/edit actions shared by the
 * admin panel (platform coupons) and the seller panel (order coupons).
 * The value is typed as a percentage or an amount and saved through
 * SaveCoupon as basis points or minor units.
 */
final class CouponForm
{
    public static function schema(Schema $schema, bool $platform): Schema
    {
        $isPercent = fn (Get $get) => $get('discount_type') === CouponDiscountType::Percent->value;

        return $schema
            ->columns(2)
            ->components([
                TextInput::make('code')->label(__('Code'))->required()->maxLength(32)
                    ->helperText(__('Letters, digits, dashes and underscores; saved in capitals.')),
                Select::make('target')->label(__('For'))
                    ->options(collect(CouponTarget::platform())->mapWithKeys(fn (CouponTarget $target) => [$target->value => $target->label()])->all())
                    ->default(CouponTarget::PlanInvoice->value)
                    ->required()
                    ->live()
                    ->selectablePlaceholder(false)
                    ->afterStateUpdated(function (?string $state, callable $set): void {
                        if ($state === CouponTarget::WalletBonus->value) {
                            $set('discount_type', CouponDiscountType::Fixed->value);
                        }
                    })
                    ->visible($platform),
                Select::make('discount_type')->label(__('Discount'))
                    ->options(collect(CouponDiscountType::cases())->mapWithKeys(fn (CouponDiscountType $type) => [$type->value => $type->label()])->all())
                    ->default(CouponDiscountType::Percent->value)
                    ->required()
                    ->live()
                    ->selectablePlaceholder(false)
                    ->disabled(fn (Get $get) => $get('target') === CouponTarget::WalletBonus->value)
                    ->dehydrated(),
                PercentInput::make('percent')->label(__('Percentage off'))->required($isPercent)->visible($isPercent),
                MoneyInput::make('fixed')
                    ->label(fn (Get $get) => $get('target') === CouponTarget::WalletBonus->value ? __('Bonus credit') : __('Amount off'))
                    ->required(fn (Get $get) => ! $isPercent($get))
                    ->visible(fn (Get $get) => ! $isPercent($get)),
                MoneyInput::make('max_discount')->label(__('At most'))->visible($isPercent)
                    ->helperText(__('Optional cap on the percentage discount.')),
                MoneyInput::make('min_amount')->label(__('Smallest amount'))
                    ->visible(fn (Get $get) => $get('target') !== CouponTarget::WalletBonus->value)
                    ->helperText(__('Optional: the code works only on this much or more.')),
                TextInput::make('max_uses')->label(__('Uses in total'))->integer()->minValue(1)
                    ->helperText(__('Leave empty for no limit.')),
                TextInput::make('per_user_limit')->label($platform ? __('Uses per buyer or company') : __('Uses per buyer'))
                    ->integer()->minValue(1)->default(1)->required(),
                DateTimePicker::make('starts_at')->label(__('Valid from'))->seconds(false),
                DateTimePicker::make('ends_at')->label(__('Valid until'))->seconds(false)->after('starts_at'),
                Toggle::make('is_active')->label(__('Active'))->default(true),
                Textarea::make('description')->label(__('Note'))->maxLength(255)->rows(2)->columnSpanFull(),
            ]);
    }

    /** @return list<TextColumn|IconColumn> */
    public static function columns(bool $platform): array
    {
        return array_values(array_filter([
            TextColumn::make('code')->label(__('Code'))->searchable()->weight('medium')->copyable(),
            $platform ? TextColumn::make('target')->label(__('For'))->badge()->formatStateUsing(fn (CouponTarget $state) => $state->label()) : null,
            TextColumn::make('value')->label(__('Value'))->state(fn (Coupon $record) => $record->valueLabel()),
            TextColumn::make('used_count')->label(__('Used'))
                ->state(fn (Coupon $record) => $record->max_uses === null ? (string) $record->used_count : "{$record->used_count} / {$record->max_uses}"),
            TextColumn::make('ends_at')->label(__('Until'))->dateTime('j M Y, H:i')->placeholder(__('No end')),
            IconColumn::make('is_active')->label(__('Active'))->boolean(),
            TextColumn::make('created_at')->label(__('Added'))->date('j M Y')->sortable(),
        ]));
    }

    public static function create(?Company $company): CreateAction
    {
        return CreateAction::make()
            ->label(__('New coupon'))
            ->authorize(fn (): bool => Outcome::user()->can('create', [Coupon::class, $company]))
            ->using(fn (array $data) => self::save($data, $company, null));
    }

    public static function edit(): EditAction
    {
        return EditAction::make()
            ->fillForm(fn (Coupon $record) => self::fill($record))
            ->using(fn (Coupon $record, array $data) => self::save($data, null, $record));
    }

    /** @return array<string, mixed> */
    private static function fill(Coupon $coupon): array
    {
        $percent = $coupon->discount_type === CouponDiscountType::Percent;

        return [
            'code' => $coupon->code,
            'target' => $coupon->target->value,
            'discount_type' => $coupon->discount_type->value,
            'percent' => $percent ? $coupon->value : null,
            'fixed' => $percent ? null : Money::minor($coupon->value),
            'max_discount' => $coupon->max_discount,
            'min_amount' => $coupon->min_amount,
            'max_uses' => $coupon->max_uses,
            'per_user_limit' => $coupon->per_user_limit,
            'starts_at' => $coupon->starts_at,
            'ends_at' => $coupon->ends_at,
            'is_active' => $coupon->is_active,
            'description' => $coupon->description,
        ];
    }

    /** @param  array<string, mixed>  $data */
    private static function save(array $data, ?Company $company, ?Coupon $coupon): Coupon
    {
        $percent = ($data['discount_type'] ?? null) === CouponDiscountType::Percent->value;
        $data['value'] = $percent ? (int) ($data['percent'] ?? 0) : (($data['fixed'] ?? null) instanceof Money ? $data['fixed']->amount : 0);
        $data['target'] ??= CouponTarget::Order->value;
        $data['max_uses'] = filled($data['max_uses'] ?? null) ? (int) $data['max_uses'] : null;
        $data['per_user_limit'] = (int) ($data['per_user_limit'] ?? 1);

        try {
            return app(SaveCoupon::class)->handle(Outcome::user(), $data, $company, $coupon);
        } catch (ValidationException $e) {
            Notification::make()->danger()->title((string) collect($e->errors())->flatten()->first())->send();

            // Keeps the modal open so the form can be corrected.
            throw new Halt;
        }
    }
}
