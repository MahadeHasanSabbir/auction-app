<?php

namespace App\Actions\Companies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Platform decisions about a company are logged with who made them and why. */
trait LogsAdminActions
{
    /** @param  array<string, mixed>  $properties */
    private function record(User $by, Model $subject, string $event, array $properties = []): void
    {
        activity('admin')
            ->causedBy($by)
            ->performedOn($subject)
            ->event($event)
            ->withProperties(array_filter($properties, fn (mixed $value) => $value !== null))
            ->log("{$event} ".class_basename($subject)." {$subject->getKey()}");
    }
}
