<?php

namespace Tests\Feature;

use App\Catalog\ProductImages;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\ImagesRelationManager;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Lunar\Models\Product;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class ProductImagesTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.faker_locale' => 'en_US']);
        config(['oneshop.enabled_locales' => ['hr', 'en'], 'media-library.queue_conversions_by_default' => false]);
        $this->artisan('oneshop:initialize')->assertSuccessful();
        Storage::fake('public');
        Storage::fake('private');
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->product = Product::factory()->create(['status' => 'draft']);
    }

    private function metadata(): array
    {
        return ['title' => 'Prednja strana', 'alt' => ['hr' => 'Plava pamučna majica', 'en' => 'Blue cotton shirt']];
    }

    private function image(?Product $product = null): Media
    {
        return app(ProductImages::class)->add($product ?? $this->product, UploadedFile::fake()->image('image.png', 20, 20), $this->metadata());
    }

    public function test_admin_uploads_a_public_image_and_gd_generates_its_thumbnail(): void
    {
        Livewire::test(ImagesRelationManager::class, ['ownerRecord' => $this->product, 'pageClass' => EditProduct::class])
            ->callTableAction('upload', data: ['image' => UploadedFile::fake()->image('original.png', 40, 30), ...$this->metadata()])
            ->assertHasNoTableActionErrors();
        $image = ProductImages::query($this->product)->sole();
        $this->assertSame('public', $image->disk);
        $this->assertSame('Prednja strana', $image->name);
        $this->assertSame('Blue cotton shirt', $image->getCustomProperty('oneshop.alt.en'));
        $this->assertTrue($image->getCustomProperty('primary'));
        Storage::disk('public')->assertExists($image->getPathRelativeToRoot());
        $this->assertTrue($image->hasGeneratedConversion('small'));
        Storage::disk('public')->assertExists($image->getPathRelativeToRoot('small'));
        $this->assertSame('draft', $this->product->fresh()->status);
    }

    public function test_webp_gallery_images_generate_a_webp_thumbnail(): void
    {
        $image = app(ProductImages::class)->add($this->product, UploadedFile::fake()->image('gallery.webp', 30, 20), $this->metadata());
        $this->assertSame('image/webp', $image->mime_type);
        $this->assertTrue($image->hasGeneratedConversion('small'));
        $bytes = Storage::disk('public')->get($image->getPathRelativeToRoot('small'));
        $this->assertSame('image/webp', getimagesizefromstring($bytes)['mime']);
    }

    public function test_image_metadata_edits_preserve_other_languages_and_custom_properties(): void
    {
        $image = $this->image();
        $image->setCustomProperty('external', 'keep');
        $image->setCustomProperty('oneshop.alt.de', 'Blaues Hemd')->save();
        Livewire::test(ImagesRelationManager::class, ['ownerRecord' => $this->product, 'pageClass' => EditProduct::class])
            ->callTableAction(EditAction::class, $image, data: [...$this->metadata(), 'title' => 'Nova fotografija'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('Nova fotografija', $image->fresh()->name);
        $this->assertSame('keep', $image->fresh()->getCustomProperty('external'));
        $this->assertSame('Blaues Hemd', $image->fresh()->getCustomProperty('oneshop.alt.de'));
    }

    public function test_reordering_sets_the_cover_and_removal_cleans_only_the_owned_image(): void
    {
        $first = $this->image();
        $second = $this->image();
        $path = $second->getPathRelativeToRoot();
        Livewire::test(ImagesRelationManager::class, ['ownerRecord' => $this->product, 'pageClass' => EditProduct::class])
            ->call('reorderTable', [$second->id, $first->id]);
        $this->assertSame($second->id, $this->product->fresh()->thumbnail->id);
        $this->assertFalse($first->fresh()->getCustomProperty('primary'));
        Livewire::test(ImagesRelationManager::class, ['ownerRecord' => $this->product, 'pageClass' => EditProduct::class])
            ->callTableAction(DeleteAction::class, $second)->assertHasNoTableActionErrors();
        $this->assertSame($first->id, $this->product->fresh()->thumbnail->id);
        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertExists($first->getPathRelativeToRoot());
    }

    public function test_invalid_file_types_sizes_and_dimensions_do_not_create_media(): void
    {
        $files = [
            UploadedFile::fake()->createWithContent('script.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->create('large.png', 5121, 'image/png'),
            UploadedFile::fake()->image('wide.jpg', 4097, 2),
            UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;'),
        ];
        foreach ($files as $file) {
            try {
                app(ProductImages::class)->add($this->product, $file, $this->metadata());
                $this->fail('An invalid image was stored.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('image', $exception->errors());
            }
        }
        $this->assertSame(0, Media::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_dimension_validation_is_visible_in_the_upload_modal(): void
    {
        Livewire::test(ImagesRelationManager::class, ['ownerRecord' => $this->product, 'pageClass' => EditProduct::class])
            ->callTableAction('upload', data: ['image' => UploadedFile::fake()->image('wide.png', 4097, 1), ...$this->metadata()])
            ->assertHasTableActionErrors(['image']);
        $this->assertSame(0, Media::query()->count());
    }

    public function test_image_limit_is_checked_against_the_locked_product(): void
    {
        for ($index = 0; $index < ProductImages::MAX_FILES; $index++) {
            $this->image();
        }
        try {
            $this->image();
            $this->fail('The gallery image limit was exceeded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('image', $exception->errors());
        }
        $this->assertSame(ProductImages::MAX_FILES, ProductImages::query($this->product)->count());
    }

    public function test_foreign_images_and_non_gallery_collections_cannot_be_modified(): void
    {
        $foreign = $this->image(Product::factory()->create());
        $private = $this->product->addMedia(UploadedFile::fake()->createWithContent('paid.txt', 'paid content'))->toMediaCollection('downloads', 'private');
        foreach ([$foreign, $private] as $image) {
            try {
                app(ProductImages::class)->remove($this->product, $image);
                $this->fail('An out-of-scope image was removed.');
            } catch (ModelNotFoundException) {
                $this->assertNotNull($image->fresh());
            }
            try {
                app(ProductImages::class)->update($this->product, $image, $this->metadata());
                $this->fail('An out-of-scope image was edited.');
            } catch (ModelNotFoundException) {
                $this->assertNotNull($image->fresh());
            }
        }
        Storage::disk('private')->assertExists($private->getPathRelativeToRoot());
    }

    public function test_foreign_or_stale_reorder_requests_preserve_the_gallery(): void
    {
        $first = $this->image();
        $second = $this->image();
        $foreign = $this->image(Product::factory()->create());
        foreach ([[$first->id], [$second->id, $foreign->id], [$first->id, $first->id]] as $ids) {
            try {
                app(ProductImages::class)->reorder($this->product, $ids);
                $this->fail('An invalid reorder request was accepted.');
            } catch (ValidationException) {
                $this->assertSame([$first->id, $second->id], ProductImages::query($this->product)->get()->modelKeys());
            }
        }
        $this->assertSame($first->id, $this->product->fresh()->thumbnail->id);
    }

    public function test_customer_cannot_upload_or_reorder_gallery_images(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/products/'.$this->product->id.'/edit')->assertForbidden();
        try {
            $this->image();
            $this->fail('A customer uploaded a catalog image.');
        } catch (AuthorizationException) {
            $this->assertSame(0, Media::query()->count());
        }
        $this->expectException(AuthorizationException::class);
        app(ProductImages::class)->reorder($this->product, []);
    }
}
