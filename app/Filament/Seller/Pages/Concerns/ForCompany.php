<?php

namespace App\Filament\Seller\Pages\Concerns;

use App\Models\Company;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** What every seller-panel page needs: the company (the Filament tenant) and who is looking. */
trait ForCompany
{
    protected function company(): Company
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company ? $tenant : abort(404);
    }

    protected function user(): User
    {
        return Auth::user() ?? abort(401);
    }

    /** Owners run the company's team, billing and profile; members only look. */
    public function canManage(): bool
    {
        return $this->user()->can('manageBilling', $this->company());
    }

    /** The timezone times are shown in. */
    public function timezone(): string
    {
        return $this->user()->displayTimezone();
    }

    protected function toast(string $message): void
    {
        Notification::make()->title($message)->success()->send();
    }

    /**
     * Runs an action class from a Filament action. When it refuses (a
     * ValidationException), the reason is shown and the modal stays open so
     * the input can be corrected.
     */
    protected function attempt(Closure $callback, ?Action $action = null): bool
    {
        try {
            $callback();

            return true;
        } catch (ValidationException $e) {
            Notification::make()->danger()->title((string) collect($e->errors())->flatten()->first())->send();
            $action?->halt();

            return false;
        }
    }
}
