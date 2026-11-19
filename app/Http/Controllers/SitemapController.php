<?php

namespace App\Http\Controllers;

use App\Enums\AuctionStatus;
use App\Enums\CompanyStatus;
use App\Enums\SitePage;
use App\Models\Auction;
use App\Models\Company;
use App\Models\Page;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * /sitemap.xml for search engines: the public pages, every seller page and
 * the auctions anyone can open (scheduled, live and sold, newest first).
 * Cached for an hour.
 */
final class SitemapController
{
    /** The sitemap format's limit per file. */
    private const MAX_URLS = 50_000;

    public function __invoke(): Response
    {
        $xml = Cache::remember('sitemap.xml', now()->addHour(), fn (): string => view('sitemap', [
            'urls' => $this->urls(),
        ])->render());

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /** @return list<array{loc: string, lastmod: ?string}> */
    private function urls(): array
    {
        $urls = [
            ['loc' => route('home'), 'lastmod' => null],
            ['loc' => route('auctions.index'), 'lastmod' => null],
            ['loc' => route('notices'), 'lastmod' => null],
            ['loc' => route('contact'), 'lastmod' => null],
        ];

        $pages = Page::query()->get(['slug', 'body_en', 'updated_at'])->keyBy(fn (Page $page) => $page->slug->value);

        foreach (SitePage::cases() as $page) {
            $content = $pages->get($page->value);

            // An empty text page is a 404, except /about, which has built-in content.
            if ($page === SitePage::About || filled($content?->body_en)) {
                $urls[] = ['loc' => route($page->routeName()), 'lastmod' => $content?->updated_at?->toAtomString()];
            }
        }

        Company::query()
            ->where('status', '!=', CompanyStatus::Pending)
            ->orderBy('id')
            ->get(['id', 'slug', 'updated_at'])
            ->each(function (Company $company) use (&$urls): void {
                $urls[] = ['loc' => route('sellers.show', $company), 'lastmod' => $company->updated_at?->toAtomString()];
            });

        Auction::query()
            ->whereIn('status', [AuctionStatus::Scheduled, AuctionStatus::Live, AuctionStatus::Sold])
            ->latest('id')
            ->limit(self::MAX_URLS - count($urls))
            ->get(['id', 'title', 'updated_at'])
            ->each(function (Auction $auction) use (&$urls): void {
                $urls[] = ['loc' => $auction->url(), 'lastmod' => $auction->updated_at?->toAtomString()];
            });

        return $urls;
    }
}
