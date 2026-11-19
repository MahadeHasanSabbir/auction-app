<?php

namespace App\Models;

use App\Enums\SitePage;
use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A text page of the site (SitePage), in English and optionally Bangla.
 * Bodies are HTML that UpdatePage has already sanitised.
 */
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['title_en', 'title_bn', 'body_en', 'body_bn'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'slug' => SitePage::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function for(SitePage $page): ?self
    {
        return static::query()->where('slug', $page)->first();
    }

    /** The title in the current language, English when there is no translation. */
    public function title(): string
    {
        return app()->getLocale() === 'bn' && filled($this->title_bn) ? (string) $this->title_bn : $this->title_en;
    }

    /** The body in the current language, English when there is no translation. */
    public function body(): ?string
    {
        return app()->getLocale() === 'bn' && filled($this->body_bn) ? $this->body_bn : $this->body_en;
    }
}
