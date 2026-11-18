<?php

namespace App\Livewire\Account;

use App\Actions\Kyc\SubmitKyc;
use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use App\Models\KycRequest;
use App\Models\User;
use App\Support\Home;
use App\Support\Kyc;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * A buyer's identity check: where it stands and, while documents are
 * wanted, the upload form (an identity document, optionally one more).
 */
#[Layout('layouts.site')]
class Verification extends Component
{
    use WithFileUploads;

    public string $idType = 'national_id';

    public ?TemporaryUploadedFile $idFile = null;

    public ?TemporaryUploadedFile $extraFile = null;

    public function mount(): void
    {
        $user = $this->user();

        if (! $user->isBuyer()) {
            $this->redirect(Home::pathFor($user));
        }
    }

    public function submit(SubmitKyc $submit): void
    {
        $this->validate([
            'idType' => ['required', 'in:'.implode(',', array_map(fn (KycDocumentType $type) => $type->value, self::identityTypes()))],
            'idFile' => ['required', 'file'],
            'extraFile' => ['nullable', 'file'],
        ], [
            'idFile.required' => __('Choose the file of your identity document.'),
        ]);

        $documents = [['type' => KycDocumentType::from($this->idType), 'file' => $this->idFile]];

        if ($this->extraFile !== null) {
            $documents[] = ['type' => KycDocumentType::Other, 'file' => $this->extraFile];
        }

        try {
            $submit->handle($this->user(), $this->user(), $documents);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn (array $messages, string $key) => [
                str_starts_with($key, 'documents.1') ? 'extraFile' : 'idFile' => $messages,
            ])->all());
        }

        $this->reset('idFile', 'extraFile');
        $this->dispatch('toast', message: __('Thank you. We will check your documents soon.'));
    }

    /** @return list<KycDocumentType> */
    public static function identityTypes(): array
    {
        return [KycDocumentType::NationalId, KycDocumentType::Passport, KycDocumentType::DrivingLicence];
    }

    public function render(): View
    {
        $user = $this->user()->refresh();

        return view('livewire.account.verification', [
            'status' => $user->kyc_status,
            'awaiting' => Kyc::awaitsDocuments($user),
            'latest' => KycRequest::query()->where('user_id', $user->id)->with('documents')->latest('id')->first(),
            'maxMb' => intdiv((int) config('auction.kyc.max_kb'), 1024),
            'rejected' => $user->kyc_status === KycStatus::Rejected,
        ])->title(__('Verification'));
    }

    private function user(): User
    {
        return Auth::user() ?? abort(401);
    }
}
