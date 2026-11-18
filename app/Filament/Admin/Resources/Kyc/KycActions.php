<?php

namespace App\Filament\Admin\Resources\Kyc;

use App\Actions\Kyc\RequestKyc;
use App\Actions\Kyc\ReviewKyc;
use App\Enums\KycStatus;
use App\Filament\Support\Outcome;
use App\Models\Company;
use App\Models\KycRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Gate;

/** Approve, reject and ask for documents: on the desk, the buyer page and the company page. */
final class KycActions
{
    public static function approve(): Action
    {
        return Action::make('approveKyc')
            ->label(__('Approve'))
            ->icon('heroicon-o-check')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('The account can bid, list and withdraw from now on.'))
            ->visible(fn (KycRequest $record) => $record->status === KycStatus::Submitted && Gate::allows('review', KycRequest::class))
            ->action(Outcome::of(fn (KycRequest $record) => app(ReviewKyc::class)->approve(Outcome::user(), $record), __('Documents approved.')));
    }

    public static function reject(): Action
    {
        return Action::make('rejectKyc')
            ->label(__('Reject'))
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->modalDescription(__('They are asked to upload the documents again.'))
            ->schema([
                Textarea::make('reason')->label(__('Reason'))->required()->maxLength(500)->helperText(__('Shown to them, so say what to fix.')),
            ])
            ->visible(fn (KycRequest $record) => $record->status === KycStatus::Submitted && Gate::allows('review', KycRequest::class))
            ->action(Outcome::of(fn (KycRequest $record, array $data) => app(ReviewKyc::class)->reject(Outcome::user(), $record, (string) $data['reason']), __('Documents rejected.')));
    }

    /** On a buyer's or a company's page: ask for (new) documents. */
    public static function request(): Action
    {
        return Action::make('requestKyc')
            ->label(__('Ask for documents'))
            ->icon('heroicon-o-identification')
            ->color('warning')
            ->modalDescription(__('Until new documents are approved they cannot bid, list or withdraw.'))
            ->schema([
                Textarea::make('note')->label(__('Note'))->maxLength(2000)->helperText(__('Shown to them. Optional.')),
            ])
            ->visible(fn (User|Company $record) => Gate::allows('review', KycRequest::class)
                && ! in_array($record->kyc_status, [KycStatus::Requested, KycStatus::Submitted], true))
            ->action(Outcome::of(fn (User|Company $record, array $data) => app(RequestKyc::class)->handle(Outcome::user(), $record, $data['note'] ?? null), __('Documents asked for.')));
    }
}
