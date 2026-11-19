<?php

namespace App\Services\Branding;

use App\Models\Setting;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The platform's look, set by the system owner: a brand colour, a logo and a
 * favicon. Stored as one `settings` row (key `branding`) and cached; nothing
 * set means the built-in look (the blue scale in resources/css/app.css, the
 * gavel wordmark).
 *
 * The colour becomes a full 50…950 scale (Filament's palette generator, in
 * OKLCH) that overrides the --color-brand-* variables on the site and the
 * primary colour of both panels. Emails use the base colour as hex.
 */
final class Branding
{
    public const KEY = 'branding';

    private const CACHE_KEY = 'settings.branding';

    /** @var array{color?: string|null, logo_path?: string|null, favicon_path?: string|null}|null */
    private ?array $values = null;

    /** The brand colour as #rrggbb, or null for the built-in blue. */
    public function color(): ?string
    {
        return $this->values()['color'] ?? null;
    }

    public function logoPath(): ?string
    {
        return $this->values()['logo_path'] ?? null;
    }

    public function faviconPath(): ?string
    {
        return $this->values()['favicon_path'] ?? null;
    }

    public function logoUrl(): ?string
    {
        return $this->url($this->logoPath());
    }

    public function faviconUrl(): ?string
    {
        return $this->url($this->faviconPath());
    }

    /**
     * The 50…950 scale for the brand colour.
     *
     * @return array<int, string>|null
     */
    public function palette(): ?array
    {
        $color = $this->color();

        return $color === null ? null : Color::hex($color);
    }

    /** CSS that overrides the site's --color-brand-* variables; null when nothing is set. */
    public function css(): ?string
    {
        $palette = $this->palette();

        if ($palette === null) {
            return null;
        }

        $variables = collect($palette)->map(fn (string $value, int $shade) => "--color-brand-{$shade}: {$value};")->implode(' ');

        return ":root { {$variables} }";
    }

    /** The colour emails use for the header band and buttons. */
    public function emailColor(): string
    {
        return $this->color() ?? '#2550eb';
    }

    /**
     * The logo as a data URI, for PDFs (dompdf reads no remote files).
     */
    public function logoDataUri(): ?string
    {
        $path = $this->logoPath();

        if ($path === null || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) Storage::disk('public')->get($path));
    }

    /**
     * @param  array{color?: string|null, logo_path?: string|null, favicon_path?: string|null}  $values
     */
    public function save(array $values): void
    {
        Setting::query()->updateOrCreate(['key' => self::KEY], ['value' => $values]);

        $this->forget();
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->values = null;
    }

    /** @return array{color?: string|null, logo_path?: string|null, favicon_path?: string|null} */
    public function values(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        try {
            /** @var array{color?: string|null, logo_path?: string|null, favicon_path?: string|null} $values */
            $values = Cache::rememberForever(self::CACHE_KEY, function (): array {
                $value = Setting::query()->where('key', self::KEY)->first()?->value;

                return is_array($value) ? $value : [];
            });
        } catch (Throwable) {
            // No database yet (install, migrations): the built-in look.
            $values = [];
        }

        return $this->values = $values;
    }

    private function url(?string $path): ?string
    {
        return $path === null ? null : Storage::disk('public')->url($path);
    }
}
