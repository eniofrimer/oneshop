<?php

namespace Tests\Feature;

use App\Catalog\CatalogTranslations;
use DOM\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Lunar\Models\Cart;
use Lunar\Models\Channel;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Order;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Lunar\Models\Transaction;
use Spatie\LaravelBlink\BlinkFacade as Blink;
use Tests\Support\PaginatedCatalog;
use Tests\TestCase;

class ProductDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('hr');
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
    }

    public function test_detail_shows_at_most_four_other_public_products_without_commerce_writes(): void
    {
        $added = PaginatedCatalog::seed();
        $shirt = ProductVariant::where('sku', 'DEMO-SHIRT-1-1')->sole()->product;
        $expected = [ProductVariant::where('sku', 'DEMO-CUP')->sole()->product_id,
            ProductVariant::where('sku', 'DEMO-BAG')->sole()->product_id, $added[0], $added[1]];
        $stock = ProductVariant::orderBy('id')->pluck('stock', 'id')->all();
        $body = $this->get(route('store.product', $shirt))->assertOk()->getContent();
        $this->assertSame($expected, $this->relatedIds($body));
        $this->assertSame($stock, ProductVariant::orderBy('id')->pluck('stock', 'id')->all());
        foreach ([Cart::class, Order::class, Transaction::class] as $model) {
            $this->assertSame(0, $model::count());
        }
    }

    public function test_drafts_archived_and_unavailable_channel_or_customer_group_scopes_never_leak(): void
    {
        $ids = PaginatedCatalog::seed();
        $shirt = ProductVariant::where('sku', 'DEMO-SHIRT-1-1')->sole()->product;
        ProductVariant::where('sku', 'DEMO-CUP')->sole()->product->update(['status' => 'draft']);
        ProductVariant::where('sku', 'DEMO-BAG')->sole()->delete();
        $group = CustomerGroup::where('default', true)->sole();
        $channel = Channel::where('default', true)->sole();
        Product::findOrFail($ids[0])->customerGroups()->updateExistingPivot($group->id, ['visible' => false]);
        Product::findOrFail($ids[1])->channels()->updateExistingPivot($channel->id, ['starts_at' => now()->addDay()]);
        Product::findOrFail($ids[2])->delete();
        Product::findOrFail($ids[3])->customerGroups()->updateExistingPivot($group->id, ['ends_at' => now()->subDay()]);
        $this->assertSame(array_slice($ids, 4, 4), $this->relatedIds($this->get(route('store.product', $shirt))->assertOk()->getContent()));
        Product::whereKeyNot($shirt->id)->update(['status' => 'draft']);
        $this->get(route('store.product', $shirt))->assertOk()->assertDontSee('data-related-products', false);
    }

    public function test_cards_match_the_catalog_with_current_stock_prices_and_private_media_filtered(): void
    {
        Queue::fake();
        Storage::fake('private');
        $cup = ProductVariant::where('sku', 'DEMO-CUP')->sole();
        $cup->update(['stock' => 0]);
        ProductVariant::where('sku', 'DEMO-BAG')->sole()->prices()->delete();
        $private = $cup->product->addMedia(UploadedFile::fake()->image('private.jpg'))
            ->usingName('PRIVATE_RELATED_IMAGE')->toMediaCollection(config('lunar.media.collection'), 'private');
        $shirt = ProductVariant::where('sku', 'DEMO-SHIRT-1-1')->sole()->product;
        $home = HTMLDocument::createFromString($this->get('/')->assertOk()->getContent());
        $body = $this->get(route('store.product', $shirt))->assertOk()
            ->assertDontSee($private->getUrl())->assertDontSee('PRIVATE_RELATED_IMAGE')->getContent();
        $detail = HTMLDocument::createFromString($body);
        foreach ($detail->querySelectorAll('[data-related-products] .product-card') as $card) {
            $href = $card->querySelector('.product-meta a')->getAttribute('href');
            $original = null;
            foreach ($home->querySelectorAll('.product-card') as $candidate) {
                if ($candidate->querySelector('.product-meta a')->getAttribute('href') === $href) {
                    $original = $candidate;
                }
            }
            $this->assertNotNull($original);
            foreach ([$card, $original] as $node) {
                foreach ($node->querySelectorAll('img') as $image) {
                    $image->removeAttribute('sizes');
                }
            }
            $this->assertSame($original->outerHTML, $card->outerHTML);
        }
    }

    public function test_more_candidates_do_not_multiply_card_queries(): void
    {
        $shirt = ProductVariant::where('sku', 'DEMO-SHIRT-1-1')->sole()->product;
        $measure = function () use ($shirt): int {
            Blink::flush();
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->get(route('store.product', $shirt))->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $small = $measure();
        PaginatedCatalog::seed();
        $large = $measure();
        $this->assertLessThanOrEqual($small + 1, $large, 'Additional related cards must use the existing batched presentation.');
        $this->assertCount(4, $this->relatedIds($this->get(route('store.product', $shirt))->assertOk()->getContent()));
    }

    public function test_breadcrumb_matches_visible_navigation_uses_trusted_urls_and_escapes_script_boundaries(): void
    {
        config(['app.url' => 'https://catalog.example.test']);
        $shirt = ProductVariant::where('sku', 'DEMO-SHIRT-1-1')->sole()->product;
        $name = 'Čaj </script><img src=x onerror=alert(1)> & "100%"';
        $shirt->update(['attribute_data' => CatalogTranslations::merge($shirt, ['hr' => ['name' => $name]])]);
        $body = $this->get('http://untrusted.example.test/proizvodi/'.$shirt->id.'?utm_source=tracking')->assertOk()
            ->assertDontSee('</script><img src=x onerror=alert(1)>', false)->getContent();
        $document = HTMLDocument::createFromString($body);
        $scripts = $document->querySelectorAll('script[type="application/ld+json"]');
        $this->assertCount(1, $scripts);
        $data = json_decode($scripts->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('BreadcrumbList', $data['@type']);
        $this->assertSame([1, 2], array_column($data['itemListElement'], 'position'));
        $this->assertSame(['https://catalog.example.test/', 'https://catalog.example.test/proizvodi/'.$shirt->id], array_column($data['itemListElement'], 'item'));
        $this->assertSame(['Kolekcija', $name], array_column($data['itemListElement'], 'name'));
        $this->assertSame($name, $document->querySelector('.product-breadcrumb [aria-current="page"]')->textContent);
    }

    public function test_invalid_origin_omits_schema_and_unrelated_pages_have_no_product_breadcrumb(): void
    {
        config(['app.url' => 'https://catalog.example.test/subdirectory']);
        $shirt = ProductVariant::where('sku', 'DEMO-SHIRT-1-1')->sole()->product;
        $this->get('/proizvodi/'.$shirt->id)->assertOk()->assertSee('Putanja proizvoda')->assertDontSee('application/ld+json');
        foreach (['/', '/kosarica', '/admin/login'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('BreadcrumbList')->assertDontSee('Putanja proizvoda');
        }
    }

    /** @return list<int> */
    private function relatedIds(string $body): array
    {
        $ids = [];
        foreach (HTMLDocument::createFromString($body)->querySelectorAll('[data-related-products] .product-meta a') as $link) {
            $this->assertSame(1, preg_match('~/proizvodi/([1-9][0-9]*)$~', $link->getAttribute('href'), $matches));
            $ids[] = (int) $matches[1];
        }

        return $ids;
    }
}
