<?php

namespace App\Http\Controllers;

use App\Models\KycDocument;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Identity documents are never public: a short-lived signed link, and the
 * viewer must still be allowed to see the request it belongs to.
 */
class KycDocumentController extends Controller
{
    public function show(KycDocument $document): StreamedResponse
    {
        $document->loadMissing('request');
        Gate::authorize('view', $document->request);

        return Storage::disk('kyc')->response($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    /** A link that works for a few minutes, for the signed-in viewer. */
    public static function link(KycDocument $document): string
    {
        return URL::temporarySignedRoute('kyc.document', now()->addMinutes((int) config('auction.kyc.link_minutes')), ['document' => $document->id]);
    }
}
