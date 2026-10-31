<?php

namespace Tests\Feature;

use App\Catalog\CatalogTranslations;
use App\Storefront\StoreSeo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lunar\Models\Country;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Tests\Support\PaginatedCatalog;
use Tests\TestCase;

class StorefrontSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('hr');
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
        config(['app.url' => 'https://catalog.example.test', 'app.debug' => false]);
    }

    public function test_preview_is_not_indexable_even_with_the_launch_flag(): void
    {
        config(['oneshop.allow_indexing' => true]);
        foreach (['local', 'testing', 'staging'] as $environment) {
            $this->app['env'] = $environment;
            $this->get('/')->assertOk()->assertHeader('X-Robots-Tag', 'noindex,nofollow')
                ->assertSee('name="robots" content="noindex,nofollow"', false);
            $this->get('/sitemap.xml')->assertNotFound();
        }
        $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertDontSee('Sitemap:')->assertDontSee('Disallow: /');
    }

    public function test_indexing_requires_explicit_production_debug_off_and_https_configuration(): void
    {
        $this->app['env'] = 'production';
        $this->get('/')->assertHeader('X-Robots-Tag', 'noindex,nofollow');
        config(['oneshop.allow_indexing' => true, 'app.debug' => true]);
        $this->get('/')->assertHeader('X-Robots-Tag', 'noindex,nofollow');
        config(['app.debug' => false, 'app.url' => 'http://catalog.example.test']);
        $this->get('/')->assertHeader('X-Robots-Tag', 'noindex,nofollow');
        config(['app.url' => 'https://catalog.example.test']);
        $this->get('/')->assertHeader('X-Robots-Tag', 'index,follow')
            ->assertSee('name="robots" content="index,follow"', false);
    }

    public function test_canonical_and_share_urls_use_the_configured_domain_not_the_request_host(): void
    {
        $cup = ProductVariant::where('sku', 'DEMO-CUP')->sole()->product;
        $response = $this->get('http://untrusted.example.test/proizvodi/'.$cup->id.'?campaign=private-marker')->assertOk();
        $head = $this->htmlHead($response->getContent());
        $this->assertStringContainsString('rel="canonical" href="https://catalog.example.test/proizvodi/'.$cup->id.'"', $head);
        $this->assertStringContainsString('property="og:url" content="https://catalog.example.test/proizvodi/'.$cup->id.'"', $head);
        $this->assertStringContainsString('property="og:image" content="https://catalog.example.test/images/', $head);
        preg_match_all('~<(?:meta[^>]+property="og:[^>]+|link[^>]+rel="canonical"[^>]+)>~u', $head, $tags);
        $this->assertStringNotContainsString('untrusted.example.test', implode('', $tags[0]));
        $this->assertStringNotContainsString('private-marker', $head);
    }

    public function test_product_descriptions_are_short_plain_text_and_attribute_escaped(): void
    {
        $cup = ProductVariant::where('sku', 'DEMO-CUP')->sole()->product;
        $cup->update(['attribute_data' => CatalogTranslations::merge($cup, ['hr' => [
            'name' => 'Šalica "Čaj"', 'description' => '<p>Čaj &amp; kava</p> " onmouseover="alert(1) '.str_repeat('Ž', 200),
        ]])]);
        $response = $this->get('/proizvodi/'.$cup->id)->assertOk();
        $head = $this->htmlHead($response->getContent());
        $this->assertStringContainsString('name="description" content="Čaj &amp; kava &quot; onmouseover=&quot;alert(1)', $head);
        $this->assertStringNotContainsString(' onmouseover="', $head);
        $this->assertStringNotContainsString('<p>', $head);
        $description = app(StoreSeo::class)->description('<p>Čaj &amp; kava</p> '.str_repeat('Ž', 200));
        $this->assertSame(180, mb_strlen($description));
        $this->assertStringEndsWith('…', $description);
        $this->assertSame('0', app(StoreSeo::class)->description('0'));
    }

    public function test_pagination_has_its_own_canonical_and_search_or_empty_pages_are_not_indexed(): void
    {
        PaginatedCatalog::seed();
        $this->launchIndexing();
        $this->get('/?page=1')->assertOk()->assertSee('rel="canonical" href="https://catalog.example.test/"', false);
        $this->get('/?page=2')->assertOk()->assertHeader('X-Robots-Tag', 'index,follow')
            ->assertSee('rel="canonical" href="https://catalog.example.test/?page=2"', false)
            ->assertSee('<title>Kolekcija — stranica 2</title>', false);
        foreach (['/?q=QA%20Stranica', '/?available=1', '/?q=QA%20Stranica&page=2'] as $path) {
            $response = $this->get($path)->assertOk()->assertHeader('X-Robots-Tag', 'noindex,follow');
            $head = $this->htmlHead($response->getContent());
            $this->assertStringNotContainsString('rel="canonical"', $head);
            $this->assertStringNotContainsString('property="og:url"', $head);
            $this->assertStringNotContainsString('QA Stranica', $head);
        }
        $this->get('/?page=100')->assertOk()->assertHeader('X-Robots-Tag', 'noindex,follow');
        $this->get('/?q=x')->assertStatus(422)->assertHeader('X-Robots-Tag', 'noindex,nofollow');
        $this->getJson('/?q=QA%20Stranica')->assertOk()->assertHeader('X-Robots-Tag', 'noindex,nofollow');
    }

    public function test_sensitive_pages_redirects_and_errors_are_not_indexed_cached_or_shared(): void
    {
        $this->launchIndexing();
        foreach (['/kosarica', '/admin/login', '/admin/products', '/checkout', '/missing-page'] as $path) {
            $response = $this->get($path)->assertHeader('X-Robots-Tag', 'noindex,nofollow')
                ->assertHeader('Referrer-Policy', 'same-origin')->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
            $this->assertStringNotContainsString('rel="canonical"', $response->getContent());
            $this->assertStringNotContainsString('property="og:', $response->getContent());
        }
        $this->get('/')->assertHeader('X-Robots-Tag', 'index,follow');
        $this->assertStringContainsString('no-store', $this->get('/')->headers->get('Cache-Control'));
    }

    public function test_sitemaps_are_disabled_without_queries_and_crawler_routes_never_create_sessions(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach (['/sitemap.xml', '/sitemap-pages.xml', '/sitemap-products-1.xml', '/robots.txt'] as $path) {
            $response = $this->get($path);
            $this->assertSame($path === '/robots.txt' ? 200 : 404, $response->status());
            $this->assertSame([], $response->headers->getCookies());
        }
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_saved_checkout_address_and_review_never_gain_public_metadata_or_cache_permissions(): void
    {
        config(['oneshop.demo_checkout' => true, 'oneshop.allow_indexing' => true]);
        $this->artisan('oneshop:demo-shipping')->assertSuccessful();
        $variant = ProductVariant::where('sku', 'DEMO-CUP')->sole();
        $this->post('/kosarica', ['variant_id' => $variant->id, 'quantity' => 1])->assertRedirect('/kosarica');
        $this->post('/checkout/adresa', ['first_name' => 'Ana', 'last_name' => 'Privatna proba',
            'contact_email' => 'private-checkout@example.test', 'contact_phone' => '+385910000000',
            'line_one' => 'Sintetička ulica 1', 'line_two' => '0', 'postcode' => '10000', 'city' => 'Zagreb',
            'country_id' => Country::where('iso2', 'HR')->sole()->id])->assertRedirect('/checkout/pregled');
        foreach (['/checkout', '/checkout/pregled'] as $path) {
            $response = $this->get($path)->assertOk()->assertHeader('X-Robots-Tag', 'noindex,nofollow')
                ->assertSee('private-checkout@example.test')->assertDontSee('rel="canonical"', false)
                ->assertDontSee('property="og:', false);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        }
        $this->assertDatabaseCount('lunar_orders', 0);
    }

    public function test_sitemap_index_and_pages_use_canonicals_without_invented_timestamps(): void
    {
        $this->launchIndexing();
        $robots = $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString("\nSitemap: https://catalog.example.test/sitemap.xml\n", $robots);
        $index = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = simplexml_load_string($index->getContent());
        $this->assertNotFalse($xml);
        $this->assertSame(['https://catalog.example.test/sitemap-pages.xml', 'https://catalog.example.test/sitemap-products-1.xml'],
            array_map(strval(...), $xml->xpath('//*[local-name()="loc"]')));
        $index->assertDontSee('lastmod')->assertDontSee('priority')->assertDontSee('changefreq');
        $this->assertSame([], $index->headers->getCookies());
        $this->get('/sitemap-pages.xml')->assertOk()->assertSee('<loc>https://catalog.example.test/</loc>', false)
            ->assertDontSee('/admin')->assertDontSee('/checkout')->assertDontSee('/kosarica')->assertDontSee('/moje-narudzbe');
    }

    public function test_sitemaps_share_publication_audience_and_archive_scope_but_retain_sold_out_products(): void
    {
        $cup = ProductVariant::where('sku', 'DEMO-CUP')->sole();
        $bag = ProductVariant::where('sku', 'DEMO-BAG')->sole()->product;
        $shirt = ProductVariant::where('sku', 'DEMO-SHIRT-1-1')->sole()->product;
        $cup->update(['stock' => 0]);
        $bag->update(['status' => 'draft']);
        $shirt->customerGroups()->updateExistingPivot(CustomerGroup::where('default', true)->sole()->id, ['visible' => false]);
        $this->launchIndexing();
        $response = $this->get('/sitemap-products-1.xml')->assertOk();
        $this->assertSame(['https://catalog.example.test/proizvodi/'.$cup->product_id], $this->locations($response->getContent()));
        $cup->product->delete();
        $this->get('/sitemap-products-1.xml')->assertNotFound();
        $this->get('/sitemap.xml')->assertNotFound();
        $this->get('/sitemap-pages.xml')->assertNotFound();
        $this->get('/robots.txt')->assertOk()->assertDontSee('Sitemap:');
    }

    public function test_sitemaps_split_at_one_thousand_with_no_duplicates_or_variant_price_queries(): void
    {
        $source = ProductVariant::where('sku', 'DEMO-CUP')->sole();
        for ($i = 0; $i < 998; $i++) {
            $product = Product::create(['product_type_id' => $source->product->product_type_id,
                'status' => 'published', 'attribute_data' => $source->product->attribute_data]);
            ProductVariant::create(['product_id' => $product->id, 'tax_class_id' => $source->tax_class_id,
                'sku' => 'QA-SITEMAP-'.$i, 'stock' => 0, 'unit_quantity' => 1, 'min_quantity' => 1, 'quantity_increment' => 1]);
        }
        $this->launchIndexing();
        $index = $this->get('/sitemap.xml')->assertOk();
        $this->assertCount(3, $this->locations($index->getContent()));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $first = $this->locations($this->get('/sitemap-products-1.xml')->assertOk()->getContent());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $second = $this->locations($this->get('/sitemap-products-2.xml')->assertOk()->getContent());
        $this->assertCount(1000, $first);
        $this->assertCount(1, $second);
        $this->assertCount(1001, array_unique([...$first, ...$second]));
        $this->assertCount(0, array_intersect($first, $second));
        $this->assertLessThanOrEqual(5, count($queries));
        $this->get('/sitemap-products-3.xml')->assertNotFound();
        foreach (['0', '-1', '01', '100000', 'text'] as $page) {
            $this->get('/sitemap-products-'.$page.'.xml')->assertNotFound();
        }
    }

    public function test_invalid_canonical_configuration_fails_closed(): void
    {
        $this->launchIndexing();
        foreach (['javascript:alert(1)', 'https://user:password@example.test', 'https://catalog.example.test/?secret=marker',
            'https://catalog.example.test/#marker', 'https://catalog.example.test/subdirectory', 'not a URL'] as $url) {
            config(['app.url' => $url]);
            $this->assertNull(app(StoreSeo::class)->origin());
            $this->assertFalse(app(StoreSeo::class)->indexingEnabled());
            $this->get('/')->assertOk()->assertHeader('X-Robots-Tag', 'noindex,nofollow')->assertDontSee('rel="canonical"', false);
            $this->get('/sitemap.xml')->assertNotFound();
        }
    }

    public function test_catalogue_sitemap_contains_only_real_indexable_default_pages(): void
    {
        PaginatedCatalog::seed();
        $this->launchIndexing();
        $response = $this->get('/sitemap-pages.xml')->assertOk();
        $this->assertSame(['https://catalog.example.test/', 'https://catalog.example.test/?page=2'], $this->locations($response->getContent()));
        $response->assertDontSee('sort=')->assertDontSee('q=')->assertDontSee('available=')->assertDontSee('page=3');
        $this->get('/?page=2')->assertOk()->assertHeader('X-Robots-Tag', 'index,follow');
        $this->get('/?page=3')->assertOk()->assertHeader('X-Robots-Tag', 'noindex,follow');
    }

    private function launchIndexing(): void
    {
        $this->app['env'] = 'production';
        config(['oneshop.allow_indexing' => true]);
    }

    private function htmlHead(string $html): string
    {
        return explode('</head>', explode('<head>', $html, 2)[1], 2)[0];
    }

    /** @return list<string> */
    private function locations(string $content): array
    {
        $xml = simplexml_load_string($content);
        $this->assertNotFalse($xml);

        return array_map(strval(...), $xml->xpath('//*[local-name()="loc"]'));
    }
}
