<?php

namespace App\Filament\Support;

use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

/**
 * Shows an action class's refusal on a Filament form: messages about a
 * field go under that field (the form's state lives in `data`), anything
 * else becomes a notice, and the page stays as it was.
 */
final class FormErrors
{
    /**
     * @param  list<string>  $fields  the form's field names
     *
     * @throws ValidationException|Halt
     */
    public static function rethrow(ValidationException $e, array $fields): never
    {
        // "images.2" belongs to the "images" field.
        $errors = collect($e->errors())
            ->groupBy(fn (array $messages, string $key) => explode('.', $key)[0], preserveKeys: true)
            ->map(fn ($group) => $group->flatten()->all())
            ->all();
        $onFields = array_intersect_key($errors, array_flip($fields));

        if (count($onFields) < count($errors)) {
            Notification::make()->danger()->title((string) collect(array_diff_key($errors, $onFields))->flatten()->first())->send();
        }

        if ($onFields === []) {
            throw new Halt;
        }

        throw ValidationException::withMessages(collect($onFields)->mapWithKeys(fn (array $messages, string $key) => ["data.{$key}" => $messages])->all());
    }
}
