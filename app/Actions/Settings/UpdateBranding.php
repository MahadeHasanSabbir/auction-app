<?php

namespace App\Actions\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Services\Branding\Branding;
use App\Services\ImageStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The system owner sets the platform's brand colour, logo and favicon.
 * Pictures go through ImageStore as PNG (emails and PDFs cannot rely on
 * WebP); a replaced or removed picture is deleted after the save.
 */
final class UpdateBranding
{
    public function __construct(
        private readonly Branding $branding,
        private readonly ImageStore $images,
    ) {}

    /**
     * @param  string|null  $color  #rrggbb, or null for the built-in blue
     *
     * @throws ValidationException when the colour or a picture is not acceptable
     */
    public function handle(
        User $owner,
        ?string $color,
        ?UploadedFile $logo = null,
        bool $removeLogo = false,
        ?UploadedFile $favicon = null,
        bool $removeFavicon = false,
    ): void {
        Gate::forUser($owner)->authorize('manage', Setting::class);

        $color = $color === null || trim($color) === '' ? null : strtolower(trim($color));

        if ($color !== null && preg_match('/^#[0-9a-f]{6}$/', $color) !== 1) {
            throw ValidationException::withMessages(['color' => __('Use a colour like #2550eb.')]);
        }

        $before = $this->branding->values();
        $after = [
            'color' => $color,
            'logo_path' => $removeLogo ? null : ($before['logo_path'] ?? null),
            'favicon_path' => $removeFavicon ? null : ($before['favicon_path'] ?? null),
        ];

        $stored = [];

        try {
            if ($logo !== null) {
                $stored[] = $after['logo_path'] = $this->picture($logo, 'logo', 600, false);
            }

            if ($favicon !== null) {
                $stored[] = $after['favicon_path'] = $this->picture($favicon, 'favicon', 128, true);
            }

            DB::transaction(function () use ($owner, $before, $after): void {
                $this->branding->save($after);

                activity('admin')
                    ->causedBy($owner)
                    ->event('branding_changed')
                    ->withProperties(['from' => $before, 'to' => $after])
                    ->log('Branding changed');
            });
        } catch (\Throwable $e) {
            array_map($this->images->delete(...), $stored);

            throw $e;
        }

        foreach (['logo_path', 'favicon_path'] as $key) {
            if (($before[$key] ?? null) !== $after[$key]) {
                $this->images->delete($before[$key] ?? null);
            }
        }
    }

    private function picture(UploadedFile $file, string $field, int $maxSide, bool $square): string
    {
        try {
            return $this->images->store($file, 'branding', maxSide: $maxSide, square: $square, format: 'png');
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => __('This file could not be read as a picture. Try a JPG or PNG.')]);
        }
    }
}
