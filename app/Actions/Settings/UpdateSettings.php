<?php

namespace App\Actions\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Services\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The system owner changes marketplace settings. A value equal to the
 * install default removes the override. The change is written to the
 * `admin` activity log with the old and new values.
 */
final class UpdateSettings
{
    public function __construct(private readonly Settings $settings) {}

    /** @param  array<string, int>  $values  key => value in stored units (bps, minor units) */
    public function handle(User $owner, array $values): void
    {
        Gate::forUser($owner)->authorize('manage', Setting::class);

        $definitions = Settings::definitions();
        $changes = [];

        foreach ($values as $key => $value) {
            $definition = $definitions[$key] ?? null;

            if ($definition === null) {
                continue;
            }

            if ($value < $definition['min'] || $value > $definition['max']) {
                throw ValidationException::withMessages([$key => __('This value is out of range.')]);
            }

            $current = $this->settings->get($key);

            if ($value !== $current) {
                $changes[$key] = ['from' => $current, 'to' => $value];
            }
        }

        $min = $values['payments.deposit_min'] ?? $this->settings->get('payments.deposit_min');
        $max = $values['payments.deposit_max'] ?? $this->settings->get('payments.deposit_max');

        if ($min > $max) {
            throw ValidationException::withMessages(['payments.deposit_max' => __('The largest deposit must not be smaller than the smallest.')]);
        }

        if ($changes === []) {
            return;
        }

        DB::transaction(function () use ($owner, $changes): void {
            foreach ($changes as $key => $change) {
                if ($change['to'] === $this->settings->default($key)) {
                    Setting::query()->where('key', $key)->delete();
                } else {
                    Setting::query()->updateOrCreate(['key' => $key], ['value' => $change['to']]);
                }
            }

            activity('admin')
                ->causedBy($owner)
                ->event('settings_changed')
                ->withProperties($changes)
                ->log('Settings changed: '.implode(', ', array_keys($changes)));
        });

        $this->settings->refresh();
    }
}
