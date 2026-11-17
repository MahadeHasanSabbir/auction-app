<?php

namespace App\Filament\Admin\Resources\Companies\Schemas;

use App\Enums\CompanyStatus;
use App\Enums\KycStatus;
use App\Models\Company;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompanyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Company'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('slug'),
                        TextEntry::make('legal_name')->placeholder('—'),
                        TextEntry::make('email')->placeholder('—'),
                        TextEntry::make('phone')->placeholder('—'),
                        TextEntry::make('address')->placeholder('—'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (CompanyStatus $state) => $state->label())
                            ->color(fn (CompanyStatus $state) => match ($state) {
                                CompanyStatus::Active => 'success',
                                CompanyStatus::Pending => 'warning',
                                CompanyStatus::Suspended => 'danger',
                            }),
                        TextEntry::make('kyc_status')->label(__('Documents'))->formatStateUsing(fn (KycStatus $state) => $state->label()),
                        IconEntry::make('auto_approve')->label(__('Auto-approve auctions'))->boolean()->falseIcon('heroicon-o-minus')->falseColor('gray'),
                        TextEntry::make('verified_at')->dateTime('j M Y, H:i')->placeholder('—'),
                        TextEntry::make('suspended_at')->dateTime('j M Y, H:i')->placeholder('—'),
                        TextEntry::make('suspension_reason')->placeholder('—'),
                        TextEntry::make('about')->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make(__('Plan'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('subscription.plan.name')->label(__('Plan'))->placeholder(__('None')),
                        TextEntry::make('subscription.status')->label(__('Status'))->formatStateUsing(fn ($state) => $state?->label())->placeholder('—'),
                        TextEntry::make('subscription.billing_interval')->label(__('Billing'))->formatStateUsing(fn ($state) => $state?->label())->placeholder('—'),
                        TextEntry::make('subscription.price')->label(__('Price per period'))->formatStateUsing(fn ($state) => $state?->format())->placeholder('—'),
                        TextEntry::make('subscription.current_period_end')->label(__('Period ends'))->dateTime('j M Y, H:i')->placeholder('—'),
                        TextEntry::make('seats')
                            ->label(__('Seats used'))
                            ->state(fn (Company $record) => $record->users_count.' / '.($record->subscription?->maxUsers() ?? '—')),
                    ]),
            ]);
    }
}
