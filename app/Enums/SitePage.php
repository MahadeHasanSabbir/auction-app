<?php

namespace App\Enums;

/** The site's text pages staff edit in the admin panel (`pages` rows, one per case). */
enum SitePage: string
{
    case About = 'about';
    case Terms = 'terms';
    case Privacy = 'privacy';
    case Faq = 'faq';

    public function label(): string
    {
        return match ($this) {
            self::About => __('About'),
            self::Terms => __('Terms of use'),
            self::Privacy => __('Privacy policy'),
            self::Faq => __('Questions and answers'),
        };
    }

    /** The public route of the page. */
    public function routeName(): string
    {
        return $this === self::About ? 'about' : 'pages.'.$this->value;
    }
}
