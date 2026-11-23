<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum's personal access token (registered in AppServiceProvider), with
 * what the admin panel needs to see who uses the API.
 */
class ApiToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    /** @return HasMany<ApiRequest, $this> */
    public function requests(): HasMany
    {
        return $this->hasMany(ApiRequest::class, 'token_id');
    }

    public function canWrite(): bool
    {
        return in_array('write', $this->abilities ?? [], true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
