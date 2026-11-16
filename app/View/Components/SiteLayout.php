<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The public and account layout, for Blade pages: <x-site-layout title="…">.
 * Livewire pages use the same view through #[Layout('layouts.site')].
 */
class SiteLayout extends Component
{
    public function __construct(public ?string $title = null) {}

    public function render(): View
    {
        return view('layouts.site');
    }
}
