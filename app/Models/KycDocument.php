<?php

namespace App\Models;

use App\Enums\KycDocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file on the private "kyc" disk. Never linked to directly; it is served
 * through an authorised, short-lived signed URL.
 */
class KycDocument extends Model
{
    /** @var list<string> */
    protected $fillable = ['type'];

    /** @var list<string> */
    protected $hidden = ['path'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => KycDocumentType::class,
        ];
    }

    /** @return BelongsTo<KycRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(KycRequest::class, 'kyc_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
