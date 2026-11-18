<?php

namespace App\Filament\Support;

use App\Models\User;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Wraps the call to an action class from a panel action: success is shown as
 * a notice, and a refusal (ValidationException) as a danger notice instead of
 * an error page.
 */
final class Outcome
{
    /**
     * For a record action: Filament passes the record and the form data.
     *
     * @param  Closure(mixed, array<string, mixed>): mixed  $callback
     */
    public static function of(Closure $callback, string $success): Closure
    {
        return function (Model $record, array $data) use ($callback, $success): void {
            self::attempt(fn () => $callback($record, $data), $success);
        };
    }

    /**
     * Runs the callback and reports how it went. True when it succeeded.
     *
     * @param  Closure(): mixed  $callback
     */
    public static function attempt(Closure $callback, string $success): bool
    {
        try {
            $callback();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title((string) collect($e->errors())->flatten()->first())->send();

            return false;
        }

        Notification::make()->success()->title($success)->send();

        return true;
    }

    /** The signed-in account. */
    public static function user(): User
    {
        return Auth::user() ?? abort(401);
    }
}
