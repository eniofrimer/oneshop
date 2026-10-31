<?php

namespace Tests\Feature;

use App\Catalog\CatalogTranslations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Tests\Support\PaginatedCatalog;
use Tests\TestCase;

class StorefrontSortingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('hr');
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
    }

    public function test_default_order_is_preserved_and_name_order_is_case_insensitive_with_stable_ties(): void
    {
        $ids = Product::orderBy('id')->pluck('id')->all();
        foreach (array_combine($ids, ['Bravo', 'alfa', 'ALFA']) as $id => $name) {
            $product = Product::findOrFail($id);
            $product->update(['attribute_data' => CatalogTranslations::merge($product, ['hr' => ['name' => $name, 'description' => 'QA sortiranje']])]);
        }
        foreach (['/', '/?sort=default'] as $path) {
            $this->assertSame($ids, $this->productIds($this->get($path)->assertOk()->getContent()));
        }
        $ascending = $this->get('/?sort=name-asc')->assertOk();
        $this->assertSame([$ids[1], $ids[2], $ids[0]], $this->productIds($ascending->getContent()));
        $descending = $this->getJson('/?sort=name-desc')->assertOk()->assertJsonPath('count', 3);
        $this->assertSame([$ids[0], $ids[1], $ids[2]], $this->productIds($descending->json('html')));
    }

    public function test_newest_uses_creation_time_and_descending_id_for_equal_timestamps(): void
    {
        $ids = Product::orderBy('id')->pluck('id')->all();
        Product::whereKey($ids)->update(['created_at' => now()->subDays(2)]);
        Product::whereKey($ids[0])->update(['created_at' => now()->subDay()]);
        $this->assertSame([$ids[0], $ids[2], $ids[1]], $this->productIds($this->get('/?sort=newest')->assertOk()->getContent()));
    }

    public function test_missing_creation_dates_follow_dated_products_and_keep_stable_ties(): void
    {
        $ids = Product::orderBy('id')->pluck('id')->all();
        Product::whereKey([$ids[0], $ids[2]])->update(['created_at' => null]);
        $expected = [$ids[1], $ids[2], $ids[0]];
        $this->assertSame($expected, $this->productIds($this->get('/?sort=newest')->assertOk()->getContent()));
        $json = $this->getJson('/?sort=newest')->assertOk();
        $this->assertSame($expected, $this->productIds($json->json('html')));
        Product::whereKey($ids)->update(['created_at' => null]);
        $this->assertSame(array_reverse($ids), $this->productIds($this->get('/?sort=newest')->assertOk()->getContent()));
    }

    public function test_sorted_pages_preserve_search_availability_and_selection_with_identical_json_results(): void
    {
        $ids = PaginatedCatalog::seed();
        $query = '/?q=QA%20Stranica&available=1&sort=name-desc';
        $first = $this->get($query)->assertOk()->assertSee('13 proizvoda')
            ->assertSee('value="name-desc" selected', false)->assertSee('sort=name-desc&amp;page=2', false);
        $this->assertSame(array_slice(array_reverse($ids), 0, 12), $this->productIds($first->getContent()));
        $second = $this->get($query.'&page=2')->assertOk()->assertSee('QA Stranica 01');
        $this->assertSame([$ids[0]], $this->productIds($second->getContent()));
        $json = $this->getJson($query.'&page=2')->assertOk()->assertJsonPath('count', 13);
        $this->assertSame([$ids[0]], $this->productIds($json->json('html')));
        ProductVariant::where('sku', 'QA-PAGE-13')->update(['stock' => 0]);
        $this->get($query.'&page=2')->assertOk()->assertSee('Ova stranica kolekcije je prazna.')
            ->assertSee('q=QA%20Stranica&amp;available=1&amp;sort=name-desc&amp;page=1', false);
    }

    public function test_sorting_never_broadens_the_publication_or_active_variant_scope(): void
    {
        $cup = ProductVariant::where('sku', 'DEMO-CUP')->sole();
        $cup->product->update(['status' => 'draft']);
        ProductVariant::where('sku', 'DEMO-BAG')->sole()->delete();
        $shirtId = ProductVariant::where('sku', 'DEMO-SHIRT-1-1')->sole()->product_id;
        foreach (['default', 'newest', 'name-asc', 'name-desc'] as $sort) {
            $json = $this->getJson('/?sort='.$sort)->assertOk()->assertJsonPath('count', 1);
            $this->assertSame([$shirtId], $this->productIds($json->json('html')));
        }
    }

    public function test_invalid_sort_has_a_readable_field_error_no_redirect_loop_and_no_catalog_queries(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->from('/?sort=invalid')->get('/?sort=invalid')->assertStatus(422)->assertHeaderMissing('Location')
            ->assertSee('catalog-sort-error', false)->assertSee('redoslijed proizvoda')->assertDontSee('validation.in')
            ->assertSee('Provjeri filtre pretrage.')->assertDontSee('Kolekcija stiže uskoro.')
            ->assertSee('href="#catalog-sort" data-validation-link', false);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->getJson('/?sort=name-asc%20DESC%3B')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->get('/?sort[]=newest')->assertStatus(400);
        $this->get('/?sort=%FF%FF')->assertStatus(400);
        $this->get('/?sort=newest&unknown=1')->assertNotFound();
    }

    public function test_sorted_duplicates_are_not_indexed_after_public_launch(): void
    {
        $this->app['env'] = 'production';
        config(['oneshop.allow_indexing' => true, 'app.debug' => false, 'app.url' => 'https://catalog.example.test']);
        $this->get('/?sort=default')->assertOk()->assertHeader('X-Robots-Tag', 'index,follow')
            ->assertSee('rel="canonical" href="https://catalog.example.test/"', false);
        foreach (['newest', 'name-asc', 'name-desc'] as $sort) {
            $this->get('/?sort='.$sort)->assertOk()->assertHeader('X-Robots-Tag', 'noindex,follow')
                ->assertDontSee('rel="canonical"', false)->assertDontSee('property="og:url"', false);
        }
    }

    public function test_sort_only_requests_share_search_throttling_without_blocking_the_plain_home_page(): void
    {
        $last = null;
        for ($i = 0; $i < 50; $i++) {
            $last = $this->get('/?sort=newest');
            if ($last->status() === 429) {
                break;
            }
            $last->assertOk();
        }
        $last->assertStatus(429)->assertHeader('Retry-After');
        $this->get('/')->assertOk();
    }

    /** @return list<int> */
    private function productIds(string $html): array
    {
        preg_match_all('~/proizvodi/([1-9][0-9]*)~', $html, $matches);

        return array_values(array_unique(array_map(intval(...), $matches[1])));
    }
}
