<?php

namespace App\Http\Controllers;

use App\Enums\SitePage;
use App\Models\Page;
use Illuminate\View\View;

/** The site's text pages (terms, privacy, FAQ), as edited by staff. */
final class PageController
{
    public function show(SitePage $page): View
    {
        $content = Page::for($page);

        abort_if($content === null || blank($content->body()), 404);

        return view('pages.show', ['page' => $content]);
    }

    /** /about: the built-in introduction, with the staff-written text below it. */
    public function about(): View
    {
        return view('about', ['page' => Page::for(SitePage::About)]);
    }
}
