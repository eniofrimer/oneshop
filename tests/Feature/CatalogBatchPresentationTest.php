<?php

namespace Tests\Feature;

use App\Models\StockReservation;
use App\Models\User;
use App\Storefront\StoreCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lunar\Models\Currency;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Order;
use Lunar\Models\Price;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\PaginatedCatalog;
use Tests\TestCase;

class CatalogBatchPresentationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<int> */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.faker_locale' => 'en_US']);
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
        $this->ids = PaginatedCatalog::seed();
    }

    public function test_twelve_cards_use_a_bounded_query_count_and_keep_input_order(): void
    {
        $catalog = app(StoreCatalog::class);
        $products = Product::query()->whereIn('id', array_slice($this->ids, 0, 12))->orderByDesc('id')->get();
        foreach ($products as $product) {
            $this->image($product, 'public', 5)->update(['generated_conversions' => ['small' => true, 'medium' => true, 'large' => true]]);
        }
        $queries = fn () => count(DB::getQueryLog());
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $one = $catalog->presentations(new Collection([$products->first()]));
            $small = $queries();
            DB::flushQueryLog();
            $cards = $catalog->presentations($products);
            $large = $queries();
            $this->assertLessThanOrEqual($small + 1, $large, 'Adding cards must not add one set of SQL queries per product.');
            $this->assertLessThanOrEqual(12, $large);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame($products->modelKeys(), $cards->pluck('id')->all());
        $this->assertSame(json_encode($one->first(), JSON_THROW_ON_ERROR), json_encode($cards->first(), JSON_THROW_ON_ERROR));
        foreach ($cards as $card) {
            $this->assertCount(1, $card['variants']);
            $this->assertSame(1000, $card['variants']->sole()['price']);
            $this->assertTrue($card['variants']->sole()['available']);
        }
    }

    public function test_each_product_gets_its_own_first_public_cover_without_other_collections_or_models(): void
    {
        $products = Product::query()->whereIn('id', array_slice($this->ids, 0, 2))->orderBy('id')->get();
        $expected = [];
        foreach ($products as $product) {
            $this->image($product, 'private', 0);
            $this->image($product, 'public', 0, 'other-collection');
            $foreign = $this->image($product, 'public', 0);
            DB::table($foreign->getTable())->where('id', $foreign->id)->update(['model_type' => User::class]);
            $cover = $this->image($product, 'public', 5);
            $this->image($product, 'public', 10);
            $expected[$product->id] = $cover;
        }
        $cards = app(StoreCatalog::class)->presentations($products);
        foreach ($cards as $card) {
            $this->assertSame($expected[$card['id']]->getUrl(), $card['image']);
            $this->assertSame('Cover '.$card['id'], $card['image_alt']);
            $this->assertCount(1, $products->find($card['id'])->getRelation('media'));
        }
    }

    public function test_batch_keeps_price_eligibility_and_active_unit_skus(): void
    {
        $products = Product::query()->whereIn('id', array_slice($this->ids, 0, 7))->orderBy('id')->get();
        $variants = ProductVariant::query()->whereIn('product_id', $products->modelKeys())->orderBy('id')->get();
        $this->price($variants[0])->replicate()->save();
        $otherCurrency = Currency::factory()->create(['code' => 'USD', 'enabled' => true, 'decimal_places' => 2, 'default' => false]);
        $this->price($variants[1])->update(['currency_id' => $otherCurrency->id]);
        $this->price($variants[2])->update(['min_quantity' => 2]);
        $this->price($variants[3])->update(['customer_group_id' => CustomerGroup::query()->where('default', true)->sole()->id]);
        $variants[4]->delete();
        $variants[5]->update(['unit_quantity' => 2]);
        $this->price($variants[6])->update(['price' => 0]);
        $cards = app(StoreCatalog::class)->presentations($products);
        foreach ($cards->take(6) as $card) {
            $this->assertCount(0, $card['variants']);
        }
        $this->assertSame(0, $cards->last()['variants']->sole()['price']);
        $this->assertTrue($cards->last()['variants']->sole()['available']);
    }

    public function test_fresh_batch_observes_prices_stock_and_authoritative_holds_without_caching_them(): void
    {
        $products = Product::query()->whereIn('id', array_slice($this->ids, 0, 2))->orderBy('id')->get();
        $variants = ProductVariant::query()->whereIn('product_id', $products->modelKeys())->orderBy('id')->get();
        $catalog = app(StoreCatalog::class);
        $first = $catalog->presentations($products);
        $this->assertTrue($first->first()['variants']->sole()['available']);
        $variants[0]->update(['stock' => 2]);
        $variants[1]->update(['stock' => 0, 'purchasable' => 'always']);
        $this->price($variants[0])->update(['price' => 1600]);
        $order = Order::factory()->create();
        foreach ([StockReservation::HELD => 2, StockReservation::CONSUMED => 3, StockReservation::RELEASED => 4] as $state => $quantity) {
            StockReservation::query()->forceCreate(['order_id' => $order->id, 'product_variant_id' => $variants[0]->id,
                'quantity' => $quantity, 'state' => $state, 'reservation_key' => Str::uuid()->toString()]);
        }
        $second = $catalog->presentations($products);
        $this->assertSame(2000, $second->first()['variants']->sole()['price']);
        $this->assertFalse($second->first()['variants']->sole()['available']);
        $this->assertTrue($second->last()['variants']->sole()['available']);
        StockReservation::query()->where('state', StockReservation::HELD)->update(['state' => StockReservation::RELEASED]);
        $this->assertTrue($catalog->presentations($products)->first()['variants']->sole()['available']);
    }

    public function test_empty_batch_makes_no_database_queries_and_disabled_currency_exposes_no_prices(): void
    {
        $catalog = app(StoreCatalog::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->assertCount(0, $catalog->presentations(new Collection));
            $this->assertCount(0, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        Currency::query()->where('code', 'EUR')->update(['enabled' => false]);
        $cards = $catalog->presentations(Product::query()->whereIn('id', array_slice($this->ids, 0, 2))->get());
        foreach ($cards as $card) {
            $this->assertCount(0, $card['variants']);
        }
    }

    private function price(ProductVariant $variant): Price
    {
        return Price::query()->whereMorphedTo('priceable', $variant)->whereHas('currency', fn ($query) => $query->where('code', 'EUR'))->sole();
    }

    private function image(Product $product, string $disk, int $position, ?string $collection = null): Media
    {
        return Media::query()->create(['model_type' => $product->getMorphClass(), 'model_id' => $product->id,
            'uuid' => Str::uuid()->toString(), 'collection_name' => $collection ?? config('lunar.media.collection'),
            'name' => 'Cover '.$product->id, 'file_name' => Str::uuid().'.webp', 'mime_type' => 'image/webp', 'disk' => $disk,
            'conversions_disk' => $disk, 'size' => 100, 'manipulations' => [], 'generated_conversions' => [],
            'responsive_images' => [], 'custom_properties' => ['oneshop' => ['alt' => ['hr' => 'Cover '.$product->id]]], 'order_column' => $position]);
    }
}
