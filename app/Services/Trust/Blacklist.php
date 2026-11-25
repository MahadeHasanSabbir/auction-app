<?php

namespace App\Services\Trust;

use App\Enums\BlacklistType;
use App\Models\BlacklistEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Email addresses, email domains and IP addresses that may not sign up or
 * sign in. Sessions already open are not ended: staff suspend the account
 * for that.
 */
final class Blacklist
{
    /** The current entry that blocks this address or IP, if any. */
    public function match(?string $email, ?string $ip): ?BlacklistEntry
    {
        $email = $email === null ? null : mb_strtolower(trim($email));
        $domain = $email !== null && str_contains($email, '@') ? Str::afterLast($email, '@') : null;

        $checks = array_filter([
            BlacklistType::Email->value => $email,
            BlacklistType::Domain->value => $domain,
            BlacklistType::Ip->value => $ip,
        ], fn (?string $value) => $value !== null && $value !== '');

        if ($checks === []) {
            return null;
        }

        return BlacklistEntry::query()
            ->current()
            ->where(function (Builder $query) use ($checks): void {
                foreach ($checks as $type => $value) {
                    $query->orWhere(fn (Builder $q) => $q->where('type', $type)->where('value', $value));
                }
            })
            ->first();
    }

    public function blocks(?string $email, ?string $ip): bool
    {
        return $this->match($email, $ip) !== null;
    }

    /** Refuses a sign-up with a plain message that does not say what matched. */
    public function ensureAllowed(?string $email, ?string $ip, string $field = 'email'): void
    {
        if ($this->blocks($email, $ip)) {
            throw ValidationException::withMessages([$field => __('We cannot open an account with these details. Contact us if you think this is a mistake.')]);
        }
    }
}
