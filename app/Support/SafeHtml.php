<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Cleans HTML written in the admin panel before it is stored and shown on
 * the site: text formatting, headings, lists, tables, links and pictures
 * only. Scripts, event handlers, styles, frames and forms are dropped, and
 * every link opens without passing the page's context.
 */
final class SafeHtml
{
    public static function clean(?string $html): ?string
    {
        if ($html === null || trim(strip_tags($html, '<img>')) === '') {
            return null;
        }

        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowRelativeLinks()
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->allowMediaSchemes(['https'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->dropAttribute('style', '*')
            ->dropAttribute('class', '*')
            ->withMaxInputLength(200_000);

        return (new HtmlSanitizer($config))->sanitize($html);
    }
}
