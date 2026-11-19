<?php

namespace Database\Factories;

use App\Enums\SitePage;
use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The terms page in English by default; pages are unique per slug, so pick
 * another with ->state(['slug' => SitePage::Faq]).
 *
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'slug' => SitePage::Terms,
            'title_en' => 'Terms of use',
            'title_bn' => null,
            'body_en' => '<p>'.fake()->paragraph().'</p>',
            'body_bn' => null,
        ];
    }
}
