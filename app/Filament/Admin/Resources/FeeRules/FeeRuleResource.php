<?php

namespace App\Filament\Admin\Resources\FeeRules;

use App\Actions\Fees\EndFeeRule;
use App\Enums\FeeChargeType;
use App\Enums\FeeUseCase;
use App\Filament\Admin\Resources\FeeRules\Pages\ManageFeeRules;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\Outcome;
use App\Filament\Support\PercentInput;
use App\Models\FeeRule;
use App\Models\UserType;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * The charges on deposits, withdrawals and sales: percent plus a fixed
 * amount, clamped, per use case and optionally per user type and gateway.
 * The most specific active rule wins (FeeResolver). A rule in force is ended
 * and replaced, never edited (FeeRulePolicy).
 */
class FeeRuleResource extends Resource
{
    protected static ?string $model = FeeRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 21;

    public static function getNavigationGroup(): string
    {
        return __('Marketplace');
    }

    public static function getNavigationLabel(): string
    {
        return __('Fees and charges');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['userType:id,name', 'creator:id,name']);
    }

    /** @return array<string, string> */
    public static function gateways(): array
    {
        return ['sslcommerz' => 'SSLCommerz', 'stripe' => 'Stripe', 'fake' => __('Test gateway')];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                // The default sale commission is every company's rate: the system
                // owner's decision, like the per-company agreements.
                Select::make('use_case')->label(__('Charged on'))
                    ->options(fn () => collect(FeeUseCase::cases())
                        ->reject(fn (FeeUseCase $case) => $case === FeeUseCase::SaleCommission && ! (Auth::user()?->isSystemOwner() ?? false))
                        ->mapWithKeys(fn (FeeUseCase $case) => [$case->value => $case->label()])->all())
                    ->required(),
                Select::make('charge_type')->label(__('Kind'))
                    ->options(collect(FeeChargeType::cases())->mapWithKeys(fn (FeeChargeType $type) => [$type->value => $type->label()])->all())
                    ->required()
                    ->helperText(__('A gateway charge passes the gateway’s cost on; a service charge is platform revenue.')),
                Select::make('user_type_id')->label(__('Only for'))
                    ->placeholder(__('Everyone'))
                    ->options(fn () => UserType::query()->where('is_active', true)->orderBy('name')->get()
                        ->mapWithKeys(fn (UserType $type) => [$type->id => $type->name.' ('.$type->account_kind->label().')'])->all()),
                Select::make('gateway')->label(__('Only through'))
                    ->placeholder(__('Every gateway'))
                    ->options(self::gateways()),
                PercentInput::make('percent_bps')->label(__('Percent'))->default(0)->required(),
                MoneyInput::make('fixed_amount')->label(__('Fixed amount'))->default('0')->required(),
                MoneyInput::make('min_amount')->label(__('At least')),
                MoneyInput::make('max_amount')->label(__('At most')),
                DateTimePicker::make('effective_from')->label(__('From'))->required()->default(now())->seconds(false),
                DateTimePicker::make('effective_until')->label(__('Until'))->seconds(false)->after('effective_from')
                    ->helperText(__('Leave empty to run until ended.')),
                TextInput::make('note')->label(__('Note'))->maxLength(255)->columnSpanFull(),
                Toggle::make('is_active')->label(__('Active'))->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        $money = fn ($state) => $state?->format();

        return $table
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('use_case')->label(__('Charged on'))->formatStateUsing(fn (FeeUseCase $state) => $state->label())->weight('medium')
                    ->description(fn (FeeRule $record) => $record->note),
                TextColumn::make('charge_type')->label(__('Kind'))->badge()->color('gray')->formatStateUsing(fn (FeeChargeType $state) => $state->label()),
                TextColumn::make('scope')->label(__('Applies to'))
                    ->state(fn (FeeRule $record) => collect([
                        $record->userType->name ?? __('Everyone'),
                        $record->gateway !== null ? (self::gateways()[$record->gateway] ?? $record->gateway) : null,
                    ])->filter()->implode(' · ')),
                TextColumn::make('charge')->label(__('Charge'))
                    ->state(fn (FeeRule $record) => collect([
                        $record->percent_bps > 0 ? PercentInput::format($record->percent_bps).'%' : null,
                        $record->fixed_amount->isPositive() ? $record->fixed_amount->format() : null,
                    ])->filter()->implode(' + ') ?: '0')
                    ->description(fn (FeeRule $record) => collect([
                        $record->min_amount !== null ? __('min :amount', ['amount' => $money($record->min_amount)]) : null,
                        $record->max_amount !== null ? __('max :amount', ['amount' => $money($record->max_amount)]) : null,
                    ])->filter()->implode(', ') ?: null),
                TextColumn::make('effective_from')->label(__('From'))->dateTime('j M Y, H:i')->sortable(),
                TextColumn::make('effective_until')->label(__('Until'))->dateTime('j M Y, H:i')->placeholder('—'),
                TextColumn::make('state')->label(__('State'))->badge()
                    ->state(fn (FeeRule $record) => self::state($record))
                    ->color(fn (string $state) => match ($state) {
                        __('In force') => 'success',
                        __('Scheduled') => 'info',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('use_case')->label(__('Charged on'))
                    ->options(collect(FeeUseCase::cases())->mapWithKeys(fn (FeeUseCase $case) => [$case->value => $case->label()])->all()),
                TernaryFilter::make('current')->label(__('In force now'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereIn('id', FeeRule::query()->effectiveAt()->select('id')),
                        false: fn (Builder $query) => $query->whereNotIn('id', FeeRule::query()->effectiveAt()->select('id')),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('end')
                    ->label(__('End'))
                    ->icon('heroicon-o-stop-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('The rule stops applying now. Add a new rule to replace it.'))
                    ->visible(fn (FeeRule $record) => Gate::allows('end', $record))
                    ->action(Outcome::of(fn (FeeRule $record) => app(EndFeeRule::class)->handle(Outcome::user(), $record), __('Rule ended.'))),
            ]);
    }

    public static function state(FeeRule $rule): string
    {
        return match (true) {
            ! $rule->is_active => __('Off'),
            $rule->effective_until !== null && ! $rule->effective_until->isFuture() => __('Ended'),
            $rule->effective_from->isFuture() => __('Scheduled'),
            default => __('In force'),
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFeeRules::route('/'),
        ];
    }
}
