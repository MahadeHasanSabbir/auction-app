<?php

namespace App\Actions\Kyc;

use App\Actions\Kyc\Concerns\ChangesKyc;
use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use App\Models\Company;
use App\Models\KycDocument;
use App\Models\KycRequest;
use App\Models\User;
use App\Services\Notifications\AccountAlerts;
use App\Support\Kyc;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A buyer, or a company's owner, hands in identity documents. The files go
 * to the private "kyc" disk; the open request (or a new one, when none was
 * asked for by staff or the last was rejected) moves to "submitted".
 */
final class SubmitKyc
{
    use ChangesKyc;

    public function __construct(private readonly AccountAlerts $alerts) {}

    /**
     * @param  list<array{type: KycDocumentType, file: UploadedFile}>  $documents
     *
     * @throws ValidationException under `documents` when something is missing or not accepted
     */
    public function handle(User $actor, User|Company $subject, array $documents): KycRequest
    {
        Gate::forUser($actor)->authorize('submit', [KycRequest::class, $subject]);

        $this->validate($subject, $documents);

        $disk = Storage::disk('kyc');
        $stored = [];

        try {
            foreach ($documents as $document) {
                $stored[] = [$document, (string) $disk->putFile('documents', $document['file'])];
            }

            $request = DB::transaction(function () use ($actor, $subject, $stored): KycRequest {
                $locked = $this->lockSubject($subject);

                if (! Kyc::awaitsDocuments($locked)) {
                    throw ValidationException::withMessages(['documents' => $locked->kyc_status === KycStatus::Submitted
                        ? __('Your documents are already being checked.')
                        : __('No documents are needed right now.')]);
                }

                $request = KycRequest::query()
                    ->where($locked instanceof Company ? 'company_id' : 'user_id', $locked->getKey())
                    ->where('status', KycStatus::Requested)
                    ->latest('id')
                    ->first() ?? new KycRequest;

                $request->forceFill([
                    'user_id' => $locked instanceof User ? $locked->id : null,
                    'company_id' => $locked instanceof Company ? $locked->id : null,
                    'requested_by' => $request->requested_by ?? $actor->id,
                    'status' => KycStatus::Submitted,
                    'submitted_at' => now(),
                ])->save();

                foreach ($stored as [$document, $path]) {
                    $row = new KycDocument(['type' => $document['type']]);
                    $row->forceFill([
                        'kyc_request_id' => $request->id,
                        'path' => $path,
                        'original_name' => mb_substr($document['file']->getClientOriginalName(), 0, 255),
                        'mime_type' => (string) $document['file']->getMimeType(),
                        'size' => (int) $document['file']->getSize(),
                        'uploaded_by' => $actor->id,
                    ])->save();
                }

                $this->setStatus($locked, KycStatus::Submitted);

                return $request;
            });
        } catch (Throwable $e) {
            foreach ($stored as [, $path]) {
                $disk->delete($path);
            }

            throw $e;
        }

        $subject->refresh();
        $this->alerts->kycSubmitted($request, $subject);

        return $request;
    }

    /** @param  list<array{type: KycDocumentType, file: UploadedFile}>  $documents */
    private function validate(User|Company $subject, array $documents): void
    {
        Validator::make(['documents' => array_map(fn (array $document) => ['type' => $document['type']->value, 'file' => $document['file']], $documents)], [
            'documents' => ['required', 'array', 'min:1', 'max:6'],
            'documents.*.type' => ['required', 'distinct'],
            'documents.*.file' => ['required', 'file', 'mimes:'.implode(',', (array) config('auction.kyc.mimes')), 'max:'.(int) config('auction.kyc.max_kb')],
        ], [
            'documents.required' => __('Add your documents.'),
            'documents.*.type.distinct' => __('Upload each kind of document once.'),
            'documents.*.file.mimes' => __('Upload a photo (JPG, PNG, WebP) or a PDF.'),
            'documents.*.file.max' => __('Each file can be at most :mb MB.', ['mb' => intdiv((int) config('auction.kyc.max_kb'), 1024)]),
        ])->validate();

        $given = array_map(fn (array $document) => $document['type'], $documents);

        foreach (Kyc::requiredDocuments($subject) as $label => $accepted) {
            if (array_uintersect($accepted, $given, fn (KycDocumentType $a, KycDocumentType $b) => strcmp($a->value, $b->value)) === []) {
                throw ValidationException::withMessages(['documents' => __('Add this document: :document.', ['document' => $label])]);
            }
        }
    }
}
