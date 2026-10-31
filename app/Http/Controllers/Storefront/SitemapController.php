<?php

namespace App\Http\Controllers\Storefront;

use App\Storefront\StoreCatalog;
use App\Storefront\StoreSeo;
use Illuminate\Http\Response;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

class SitemapController
{
    public const int PRODUCTS_PER_PAGE = 1000;

    public function robots(StoreSeo $seo): Response
    {
        // Crawlers must be able to fetch pages to see their noindex directive.
        $body = "User-agent: *\nDisallow:\n";
        if ($seo->indexingEnabled()) {
            $body .= "\nSitemap: ".$seo->url('store.sitemap')."\n";
        }

        return response($body)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function index(StoreSeo $seo, StoreCatalog $catalog): Response
    {
        abort_unless($seo->indexingEnabled(), 404);
        $pages = (int) ceil($catalog->products()->count() / self::PRODUCTS_PER_PAGE);
        abort_if($pages > 49999, 503);
        $urls = [$seo->url('store.sitemap.pages')];
        for ($page = 1; $page <= $pages; $page++) {
            $urls[] = $seo->url('store.sitemap.products', ['page' => $page]);
        }

        return response()->view('seo.sitemap-index', ['urls' => $urls])->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function pages(StoreSeo $seo): Response
    {
        abort_unless($seo->indexingEnabled(), 404);

        return $this->xml([$seo->url('store.home')]);
    }

    public function products(StoreSeo $seo, StoreCatalog $catalog, int $page): Response
    {
        abort_unless($seo->indexingEnabled(), 404);
        $query = $catalog->products();
        $pages = (int) ceil($query->count() / self::PRODUCTS_PER_PAGE);
        abort_if($page > $pages, 404);
        $ids = $query->offset(($page - 1) * self::PRODUCTS_PER_PAGE)->limit(self::PRODUCTS_PER_PAGE)->pluck('id');

        return $this->xml($ids->map(fn (int $id): ?string => $seo->url('store.product', ['productId' => $id]))->all());
    }

    /** @param array<?string> $urls */
    private function xml(array $urls): Response
    {
        $sitemap = Sitemap::create();
        foreach ($urls as $url) {
            if ($url !== null) {
                $sitemap->add(Url::create($url));
            }
        }

        return response($sitemap->render())->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
