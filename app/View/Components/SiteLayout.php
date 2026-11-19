<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The public and account layout, for Blade pages: <x-site-layout title="…">.
 * Livewire pages use the same view through #[Layout('layouts.site')] and pass
 * the same values with ->title() and ->layoutData([...]).
 *
 * The description, image and canonical URL become meta tags (search
 * results, link previews); noindex keeps a page out of search engines.
 */
class SiteLayout extends Component
{
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?string $image = null,
        public ?string $canonical = null,
        public bool $noindex = false,
    ) {}

    public function render(): View
    {
        return view('layouts.site');
    }
}
