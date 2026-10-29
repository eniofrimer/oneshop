<?php

namespace Tests\Feature;

use App\Catalog\ProductImages;
use App\Models\User;
use App\Storefront\StoreCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Lunar\Models\ProductOption;
use Lunar\Models\ProductVariant;
use Tests\TestCase;

class StorefrontProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.faker_locale' => 'en_US']);
        app()->setLocale('hr');
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
    }

    public function test_option_controls_follow_admin_preference_and_only_include_usable_product_values(): void
    {
        $variant = ProductVariant::query()->where('sku', 'DEMO-SHIRT-1-1')->sole();
        $colour = ProductOption::query()->where('handle', 'demo-colour')->sole();
        $colour->update(['meta' => ['oneshop' => ['display' => 'radio']]]);
        $foreign = $colour->values()->create(['name' => ['hr' => 'Nepovezana vrijednost'], 'position' => 99]);
        $data = app(StoreCatalog::class)->selection($variant->product);
        $this->assertSame('radio', $data['options'][0]['display']);
        $this->assertSame('dropdown', $data['options'][1]['display']);
        $this->assertCount(2, $data['options'][0]['values']);
        $this->assertNotContains($foreign->id, array_column($data['options'][0]['values'], 'id'));
        $this->assertCount(4, $data['variants']);
        $this->get(route('store.product', $variant->product))->assertOk()->assertSee('data-option-controls', false)
            ->assertSee('type="radio"', false)->assertSee('Veličina')->assertSee('name="variant_id"', false)->assertDontSee('Nepovezana vrijednost');
    }

    public function test_archived_or_unpriced_skus_are_absent_from_interactive_choices(): void
    {
        $variant = ProductVariant::query()->where('sku', 'DEMO-SHIRT-1-1')->sole();
        $product = $variant->product;
        $variant->delete();
        $unpriced = ProductVariant::query()->where('sku', 'DEMO-SHIRT-1-2')->sole();
        $unpriced->prices()->delete();
        $data = app(StoreCatalog::class)->selection($product);
        $this->assertCount(2, $data['variants']);
        $this->assertSame(['Pijesak'], array_column($data['options'][0]['values'], 'name'));
        $this->assertNotContains($variant->id, array_column($data['variants'], 'id'));
        $this->assertNotContains($unpriced->id, array_column($data['variants'], 'id'));
        $this->get(route('store.product', $product))->assertOk()->assertDontSee('DEMO-SHIRT-1-1')->assertDontSee('DEMO-SHIRT-1-2');
    }

    public function test_selection_json_and_visible_option_labels_escape_html(): void
    {
        $variant = ProductVariant::query()->where('sku', 'DEMO-SHIRT-1-1')->sole();
        $value = $variant->values()->first();
        $value->update(['name' => ['hr' => '</script><img src=x onerror=alert(1)>']]);
        $this->get(route('store.product', $variant->product))->assertOk()
            ->assertDontSee('</script><img src=x onerror=alert(1)>', false)
            ->assertSee('\\u003C', false)->assertSee('&lt;/script&gt;', false);
    }

    public function test_gallery_uses_ordered_public_photos_with_alt_text_and_excludes_private_media(): void
    {
        Queue::fake();
        Storage::fake('public');
        Storage::fake('private');
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true, 'is_super_admin' => true])->save();
        $this->actingAs($admin);
        $product = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole()->product;
        $images = app(ProductImages::class);
        $first = $images->add($product, UploadedFile::fake()->image('first.jpg'), ['title' => 'Prva', 'alt' => ['hr' => 'Prva fotografija šalice']]);
        $second = $images->add($product, UploadedFile::fake()->image('second.jpg'), ['title' => 'Druga', 'alt' => ['hr' => 'Druga fotografija šalice']]);
        $private = $product->addMedia(UploadedFile::fake()->image('private.jpg'))->usingName('PRIVATE_IMAGE_DO_NOT_RENDER')->toMediaCollection('private-gallery', 'private');
        $images->reorder($product, [$second->id, $first->id]);
        $gallery = app(StoreCatalog::class)->gallery($product);
        $this->assertSame([$second->getUrl(), $first->getUrl()], array_column($gallery, 'url'));
        $this->assertSame('Druga fotografija šalice', $gallery[0]['alt']);
        $this->get(route('store.product', $product))->assertOk()->assertSee('data-gallery-thumbnail', false)
            ->assertSee('Druga fotografija šalice')->assertSee('Prva fotografija šalice')->assertDontSee($private->getUrl())->assertDontSee('PRIVATE_IMAGE_DO_NOT_RENDER');
    }
}
