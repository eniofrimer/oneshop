<?php

namespace Tests\Feature;

use App\Catalog\ProductImages;
use App\Catalog\VariantPhotos;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\User;
use App\Storefront\StoreCatalog;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class VariantPhotosTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.faker_locale' => 'en_US']);
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
        Storage::fake('public');
        Storage::fake('private');
        Queue::fake();
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true, 'is_super_admin' => true])->save();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->variant = ProductVariant::query()->where('sku', 'DEMO-SHIRT-1-1')->sole();
        $this->product = $this->variant->product;
    }

    private function image(string $title, ?Product $product = null): Media
    {
        return app(ProductImages::class)->add($product ?? $this->product, UploadedFile::fake()->image('photo.jpg', 20, 20),
            ['title' => $title, 'alt' => ['hr' => $title]]);
    }

    public function test_admin_action_selects_primary_photo_without_changing_variant_price_stock_or_gallery_order(): void
    {
        $first = $this->image('Maslinasta');
        $second = $this->image('Pijesak');
        $this->variant->update(['sku' => '0']);
        $this->variant->refresh();
        $this->variant->images()->attach($first->id, ['primary' => true, 'position' => 9]);
        $this->variant->images()->attach($second->id, ['primary' => false, 'position' => 4]);
        $before = $this->variant->getRawOriginal();
        $prices = $this->variant->prices->map->getRawOriginal()->all();
        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $this->product, 'pageClass' => EditProduct::class])
            ->callTableAction('photo', $this->variant, data: ['image_id' => (string) $second->id])->assertHasNoTableActionErrors()
            ->assertSee('alt="Fotografija SKU-a 0"', false);
        $this->assertSame($second->id, VariantPhotos::current($this->variant->fresh())->id);
        $this->assertSame([4, 9], $this->variant->images()->get()->pluck('pivot.position')->all());
        $this->assertSame(2, $this->variant->images()->count());
        $this->assertSame(1, $this->variant->images()->wherePivot('primary', true)->count());
        $this->assertSame($before, $this->variant->fresh()->getRawOriginal());
        $this->assertSame($prices, $this->variant->fresh()->prices->map->getRawOriginal()->all());
        $this->assertSame([$first->id, $second->id], ProductImages::query($this->product)->pluck('id')->all());
        $audit = Activity::query()->where('event', 'sku-photo-updated')->sole();
        $this->assertSame([$second->id], $audit->properties['after_media_ids']);
        $this->assertStringNotContainsString('storage/', $audit->properties->toJson());
        app(VariantPhotos::class)->assign($this->product, $this->variant, $second->id);
        $this->assertSame(1, Activity::query()->where('event', 'sku-photo-updated')->count());
        app(VariantPhotos::class)->assign($this->product, $this->variant, null);
        $this->assertNull(VariantPhotos::current($this->variant));
        $this->assertSame(2, $this->variant->images()->count());
    }

    public function test_foreign_private_non_image_and_malformed_choices_are_refused_without_changing_primary(): void
    {
        $first = $this->image('Vlastita');
        app(VariantPhotos::class)->assign($this->product, $this->variant, $first->id);
        $other = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole()->product;
        $foreign = $this->image('Tuđa', $other);
        $private = $this->product->addMedia(UploadedFile::fake()->image('private.jpg'))
            ->toMediaCollection(config('lunar.media.collection'), 'private');
        $document = $this->product->media()->create(['name' => 'Not a photo', 'file_name' => 'document.pdf',
            'collection_name' => config('lunar.media.collection'), 'disk' => 'public', 'mime_type' => 'application/pdf',
            'size' => 5, 'manipulations' => [], 'custom_properties' => [], 'generated_conversions' => [], 'responsive_images' => []]);
        foreach ([$foreign->id, $private->id, $document->id, ['unexpected'], 0, -1] as $choice) {
            try {
                app(VariantPhotos::class)->assign($this->product, $this->variant, $choice);
                $this->fail('Invalid photo choice was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('image_id', $exception->errors());
            }
            $this->assertSame($first->id, VariantPhotos::current($this->variant->fresh())->id);
        }
    }

    public function test_customer_or_revoked_administrator_cannot_change_a_photo(): void
    {
        $image = $this->image('Vlastita');
        $this->actingAs(User::factory()->create());
        try {
            app(VariantPhotos::class)->assign($this->product, $this->variant, $image->id);
            $this->fail('Customer changed a variant photo.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $this->variant->images()->count());
        }
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true, 'is_super_admin' => true])->save();
        $this->actingAs($user);
        $page = Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $this->product, 'pageClass' => EditProduct::class]);
        $user->forceFill(['is_admin' => false, 'is_super_admin' => false])->save();
        $page->call('mountAction', 'photo', ['record' => (string) $this->variant->id], ['table' => true])->assertForbidden();
        $this->assertSame(0, $this->variant->images()->count());
    }

    public function test_other_product_and_archived_skus_cannot_be_modified(): void
    {
        $image = $this->image('Vlastita');
        $other = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        foreach ([$other, $this->variant] as $variant) {
            if ($variant->id === $this->variant->id) {
                $variant->delete();
            }
            try {
                app(VariantPhotos::class)->assign($this->product, $variant, $image->id);
                $this->fail('Unavailable SKU changed.');
            } catch (ModelNotFoundException) {
                $this->assertSame(0, $variant->images()->count());
            }
        }
    }

    public function test_reader_ignores_foreign_and_private_primary_relations_even_if_imported(): void
    {
        $other = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole()->product;
        $foreign = $this->image('Tuđa', $other);
        $private = $this->product->addMedia(UploadedFile::fake()->image('private.jpg'))
            ->toMediaCollection(config('lunar.media.collection'), 'private');
        $this->variant->images()->attach([$foreign->id => ['primary' => true], $private->id => ['primary' => true]]);
        $this->variant->load('images');
        $this->assertNull(VariantPhotos::current($this->variant));
        $this->assertNull(VariantPhotos::current($this->variant->fresh()));
    }

    public function test_selected_public_photo_is_presented_in_selection_and_cart_and_does_not_replace_product_cover(): void
    {
        $cover = $this->image('Naslovna');
        $photo = $this->image('Pijesak');
        app(VariantPhotos::class)->assign($this->product, $this->variant, $photo->id);
        $catalog = app(StoreCatalog::class);
        $this->assertSame($cover->getUrl(), $catalog->presentation($this->product)['image']);
        $selection = collect($catalog->selection($this->product)['variants'])->firstWhere('id', $this->variant->id);
        $this->assertSame($photo->getUrl(), $selection['photo']['preview']);
        $this->assertSame('Pijesak', $selection['photo']['alt']);
        $this->assertNull(collect($catalog->selection($this->product)['variants'])->firstWhere('id', $this->product->variants->firstWhere('sku', 'DEMO-SHIRT-2-1')->id)['photo']);
        $this->post(route('store.cart.add'), ['variant_id' => $this->variant->id, 'quantity' => 1])->assertRedirect(route('store.cart'));
        $this->get(route('store.cart'))->assertOk()->assertSee($photo->getUrl(), false)->assertDontSee($cover->getUrl(), false);
    }

    public function test_removed_photo_cascades_its_pivot_and_cart_returns_to_product_cover(): void
    {
        $cover = $this->image('Naslovna');
        $photo = $this->image('Varijanta');
        app(VariantPhotos::class)->assign($this->product, $this->variant, $photo->id);
        $this->post(route('store.cart.add'), ['variant_id' => $this->variant->id, 'quantity' => 1]);
        app(ProductImages::class)->remove($this->product, $photo);
        $this->assertSame(0, $this->variant->images()->count());
        $this->get(route('store.cart'))->assertOk()->assertSee($cover->getUrl(), false)->assertDontSee($photo->getUrl(), false);
    }

    public function test_single_sku_photo_is_rendered_without_javascript_and_untrusted_alt_is_escaped(): void
    {
        $cup = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $cover = $this->image('Naslovna šalice', $cup->product);
        $photo = $this->image('Varijanta šalice', $cup->product);
        app(ProductImages::class)->update($cup->product, $photo, ['title' => $photo->name, 'alt' => ['hr' => '</script><img src=x onerror=alert(1)>']]);
        app(VariantPhotos::class)->assign($cup->product, $cup, $photo->id);
        $response = $this->get(route('store.product', $cup->product))->assertOk()->assertDontSee('</script><img src=x', false);
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $main = $xpath->query('//img[@data-gallery-main]')->item(0);
        $this->assertSame($photo->getUrl(), $main->getAttribute('src'));
        $this->assertSame('</script><img src=x onerror=alert(1)>', $main->getAttribute('alt'));
        $active = $xpath->query('//a[@data-gallery-thumbnail and @aria-current]');
        $this->assertSame(1, $active->length);
        $this->assertSame($photo->getUrl(), $active->item(0)->getAttribute('href'));
        $this->assertNotSame($cover->getUrl(), $main->getAttribute('src'));
    }

    public function test_demo_sand_photo_matches_sku_and_real_uploads_override_demo_selection(): void
    {
        $sand = ProductVariant::query()->where('sku', 'DEMO-SHIRT-2-1')->sole();
        $catalog = app(StoreCatalog::class);
        $this->assertCount(2, $catalog->gallery($this->product));
        $selection = collect($catalog->selection($this->product)['variants'])->firstWhere('id', $sand->id);
        $this->assertStringEndsWith('shirt-sand-800.webp', $selection['photo']['preview']);
        $this->post(route('store.cart.add'), ['variant_id' => $sand->id, 'quantity' => 1]);
        $this->get(route('store.cart'))->assertOk()->assertSee('shirt-sand-800.webp');
        $cover = $this->image('Vlastita fotografija');
        $this->assertCount(1, $catalog->gallery($this->product));
        $this->assertNull(collect($catalog->selection($this->product)['variants'])->firstWhere('id', $sand->id)['photo']);
        $this->get(route('store.cart'))->assertOk()->assertSee($cover->getUrl(), false)->assertDontSee('shirt-sand-800.webp');
    }

    public function test_selected_public_original_never_uses_private_conversions(): void
    {
        $photo = $this->image('Javni original');
        $photo->forceFill(['conversions_disk' => 'private', 'generated_conversions' => ['small' => true, 'medium' => true, 'large' => true]])->save();
        Storage::disk('private')->put($photo->getPathRelativeToRoot('medium'), 'private conversion fixture');
        app(VariantPhotos::class)->assign($this->product, $this->variant, $photo->id);
        $selection = collect(app(StoreCatalog::class)->selection($this->product)['variants'])->firstWhere('id', $this->variant->id);
        $this->assertSame($photo->getUrl(), $selection['photo']['preview']);
        $this->assertSame($photo->getUrl(), $selection['photo']['thumbnail']);
        $this->assertNull($selection['photo']['srcset']);
        $this->post(route('store.cart.add'), ['variant_id' => $this->variant->id, 'quantity' => 1]);
        $this->get(route('store.cart'))->assertOk()->assertSee($photo->getUrl(), false)->assertDontSee('/conversions/', false);
    }
}
