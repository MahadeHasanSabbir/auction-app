<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The narrow, centred layout of the sign-in, sign-up and code pages.
 */
class AuthLayout extends Component
{
    /** @param  string  $width  a Tailwind max-width class for the card */
    public function __construct(public ?string $title = null, public string $width = 'max-w-md') {}

    public function render(): View
    {
        return view('layouts.auth');
    }
}
