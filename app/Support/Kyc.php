<?php

namespace App\Support;

use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use App\Models\Company;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Validation\ValidationException;

/**
 * Identity checks (KYC). A buyer or a company that is not cleared cannot
 * bid, buy now, list or withdraw; with `auction.kyc.required` every new
 * account starts out asked for documents.
 */
final class Kyc
{
    /** The status a new buyer or company starts with. */
    public static function startingStatus(): KycStatus
    {
        return config('auction.kyc.required') ? KycStatus::Requested : KycStatus::NotRequired;
    }

    /** @throws ValidationException under $field when the subject is not cleared */
    public static function ensureCleared(User|Company $subject, string $field): void
    {
        $status = $subject->kyc_status;

        if ($status->isCleared()) {
            return;
        }

        $company = $subject instanceof Company;

        throw ValidationException::withMessages([$field => match ($status) {
            KycStatus::Submitted => $company
                ? __('The platform is checking the company’s documents. You can list once they are approved.')
                : __('We are checking your documents. You can continue once they are approved.'),
            KycStatus::Rejected => $company
                ? __('The company’s documents were not accepted. Upload them again on the Verification page.')
                : __('Your documents were not accepted. Upload them again on the Verification page.'),
            default => $company
                ? __('Verify the company first: upload its documents on the Verification page.')
                : __('Verify your identity first: upload your documents on the Verification page.'),
        }]);
    }

    /** Whose identity a wallet's withdrawals depend on. */
    public static function subjectOf(Wallet $wallet): User|Company|null
    {
        return match (true) {
            $wallet->company_id !== null => Company::query()->find($wallet->company_id),
            $wallet->user_id !== null => User::query()->find($wallet->user_id),
            default => null,
        };
    }

    /**
     * The documents a submission must hold: one of each group.
     *
     * @return array<string, list<KycDocumentType>>
     */
    public static function requiredDocuments(User|Company $subject): array
    {
        $identity = [KycDocumentType::NationalId, KycDocumentType::Passport, KycDocumentType::DrivingLicence];

        return $subject instanceof Company
            ? [__('Owner’s identity document') => $identity, __('Trade licence') => [KycDocumentType::TradeLicence]]
            : [__('Identity document') => $identity];
    }

    /** Whether the subject may (re)submit documents now. */
    public static function awaitsDocuments(User|Company $subject): bool
    {
        return in_array($subject->kyc_status, [KycStatus::Requested, KycStatus::Rejected], true);
    }
}
