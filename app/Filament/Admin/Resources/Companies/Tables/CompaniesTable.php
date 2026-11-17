<?php

namespace App\Filament\Admin\Resources\Companies\Tables;

use App\Enums\CompanyStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Admin\Resources\Companies\Actions\CompanyActions;
use App\Models\Company;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CompanyStatus $state) => $state->label())
                    ->color(fn (CompanyStatus $state) => match ($state) {
                        CompanyStatus::Active => 'success',
                        CompanyStatus::Pending => 'warning',
                        CompanyStatus::Suspended => 'danger',
                    }),
                TextColumn::make('subscription.plan.name')->label(__('Plan'))->placeholder(__('None')),
                TextColumn::make('subscription.status')
                    ->label(__('Plan status'))
                    ->badge()
                    ->formatStateUsing(fn (SubscriptionStatus $state) => $state->label())
                    ->color(fn (SubscriptionStatus $state) => match ($state) {
                        SubscriptionStatus::Active => 'success',
                        SubscriptionStatus::Trialing => 'info',
                        SubscriptionStatus::PastDue, SubscriptionStatus::Incomplete => 'warning',
                        default => 'danger',
                    })
                    ->placeholder('—'),
                TextColumn::make('users_count')->label(__('People')),
                IconColumn::make('auto_approve')->label(__('Auto-approve'))->boolean()->falseIcon('heroicon-o-minus')->falseColor('gray'),
                TextColumn::make('created_at')->label(__('Signed up'))->date('j M Y')->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(CompanyStatus::cases())->mapWithKeys(fn (CompanyStatus $status) => [$status->value => $status->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    CompanyActions::verify(),
                    CompanyActions::suspend(),
                    CompanyActions::reinstate(),
                    CompanyActions::autoApprove(),
                    CompanyActions::assignPlan(),
                ]),
            ])
            ->recordUrl(fn (Company $record) => null);
    }
}
