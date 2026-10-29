<?php

namespace Tests\Feature;

use App\Catalog\OptionEditor;
use App\Catalog\ProductEditor;
use App\Catalog\VariantEditor;
use App\Digital\DigitalAssetEditor;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;
use Lunar\Models\Product;
use Lunar\Models\ProductType;
use Lunar\Models\ProductVariant;
use Lunar\Models\TaxClass;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class VariantArchiveTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.faker_locale' => 'en_US']);
        $this->artisan('oneshop:initialize')->assertSuccessful();
        Storage::fake('private');
        Storage::fake('public');
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $option = app(OptionEditor::class)->saveOption(['names' => ['hr' => 'Boja'], 'display' => 'radio']);
        $value = app(OptionEditor::class)->saveValue($option, ['names' => ['hr' => 'Plava'], 'position' => 0]);
        $this->product = app(ProductEditor::class)->save([
            'product_type_id' => ProductType::factory()->create()->id, 'translations' => ['hr' => ['name' => 'Knjiga']], 'option_ids' => [$option->id],
        ]);
        $this->variant = app(VariantEditor::class)->save($this->product, [
            'sku' => 'BOOK-BLUE', 'price_eur' => '12.35', 'stock' => 10, 'tax_class_id' => TaxClass::factory()->create()->id,
            'purchasable' => 'in_stock', 'shippable' => false, 'option_values' => [$option->id => $value->id],
        ]);
    }

    public function test_variant_can_be_archived_and_restored_in_filament_without_repricing(): void
    {
        $component = Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $this->product, 'pageClass' => EditProduct::class]);
        $component->callTableAction('archive', $this->variant)->assertHasNoTableActionErrors()->assertCanNotSeeTableRecords([$this->variant]);
        $this->assertTrue($this->variant->fresh()->trashed());
        $component->filterTable('trashed', true)->assertCanSeeTableRecords([$this->variant])
            ->callTableAction('restore', $this->variant)->assertHasNoTableActionErrors()->assertCanSeeTableRecords([$this->variant]);
        $this->assertFalse($this->variant->fresh()->trashed());
        $this->assertSame(1235, $this->variant->prices()->sole()->price->value);
        $this->assertSame(10, $this->variant->fresh()->stock);
        $this->assertSame(['sku-archived', 'sku-restored'], Activity::query()->whereIn('event', ['sku-archived', 'sku-restored'])->orderBy('id')->pluck('event')->all());
    }

    public function test_archiving_preserves_options_media_private_assets_and_order_snapshots(): void
    {
        $asset = app(DigitalAssetEditor::class)->upload($this->variant, UploadedFile::fake()->createWithContent('book.pdf', 'book'), 'Knjiga');
        $image = $this->product->addMedia(UploadedFile::fake()->image('cover.png', 20, 20))->toMediaCollection('images', 'public');
        $this->variant->images()->attach($image->id);
        $order = Order::factory()->create(['currency_code' => 'EUR', 'placed_at' => now()]);
        $line = OrderLine::factory()->create(['order_id' => $order->id, 'purchasable_id' => $this->variant->id, 'description' => 'Povijesna knjiga']);
        app(VariantEditor::class)->setArchived($this->product, $this->variant, true);
        $this->assertSame(1, $this->variant->values()->count());
        $this->assertSame(1, $this->variant->images()->count());
        $this->assertSame(1, $this->variant->prices()->count());
        $this->assertNotNull($asset->fresh());
        Storage::disk('private')->assertExists($asset->path);
        Storage::disk('public')->assertExists($image->getPathRelativeToRoot());
        $this->assertSame('Povijesna knjiga', $line->fresh()->description);
        $this->get('/admin/orders/'.$order->id)->assertOk();
    }

    public function test_duplicate_combination_blocks_restoration_until_the_replacement_is_archived(): void
    {
        app(VariantEditor::class)->setArchived($this->product, $this->variant, true);
        $data = VariantEditor::formData($this->variant);
        $data['sku'] = 'REPLACEMENT';
        $replacement = app(VariantEditor::class)->save($this->product, $data);
        try {
            app(VariantEditor::class)->setArchived($this->product, $this->variant, false);
            $this->fail('A duplicate active combination was restored.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sku', $exception->errors());
        }
        $this->assertTrue($this->variant->fresh()->trashed());
        app(VariantEditor::class)->setArchived($this->product, $replacement, true);
        app(VariantEditor::class)->setArchived($this->product, $this->variant, false);
        $this->assertFalse($this->variant->fresh()->trashed());
        $this->assertTrue($replacement->fresh()->trashed());
    }

    public function test_used_product_options_can_be_removed_after_the_old_skus_are_archived(): void
    {
        app(VariantEditor::class)->setArchived($this->product, $this->variant, true);
        app(ProductEditor::class)->save([
            'product_type_id' => $this->product->product_type_id, 'translations' => ProductEditor::translations($this->product), 'option_ids' => [],
        ], $this->product);
        $this->assertSame(0, $this->product->productOptions()->count());
        $this->assertSame(1, $this->variant->values()->count());
    }

    public function test_published_products_must_be_withdrawn_before_archiving_or_restoring(): void
    {
        foreach ([true, false] as $archived) {
            $this->product->update(['status' => 'published']);
            try {
                app(VariantEditor::class)->setArchived($this->product, $this->variant, $archived);
                $this->fail('A published product archive was changed.');
            } catch (ValidationException) {
                $this->assertSame('published', $this->product->fresh()->status);
            }
        }
        $this->assertFalse($this->variant->fresh()->trashed());
    }

    public function test_foreign_variants_and_customers_cannot_change_archives(): void
    {
        $foreign = ProductVariant::factory()->create();
        try {
            app(VariantEditor::class)->setArchived($this->product, $foreign, true);
            $this->fail('A foreign variant was archived.');
        } catch (ModelNotFoundException) {
            $this->assertFalse($foreign->fresh()->trashed());
        }
        $this->actingAs(User::factory()->create());
        $this->expectException(AuthorizationException::class);
        app(VariantEditor::class)->setArchived($this->product, $this->variant, true);
    }
}
