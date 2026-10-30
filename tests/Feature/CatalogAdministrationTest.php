<?php

namespace Tests\Feature;

use App\Catalog\ProductEditor;
use App\Catalog\ProductImages;
use App\Catalog\VariantEditor;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Currency;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Price;
use Lunar\Models\Product;
use Lunar\Models\ProductType;
use Lunar\Models\ProductVariant;
use Lunar\Models\TaxClass;
use Tests\TestCase;

class CatalogAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private ProductType $type;

    private TaxClass $tax;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.faker_locale' => 'en_US']);
        config(['oneshop.enabled_locales' => ['hr', 'en']]);
        $this->artisan('oneshop:initialize')->assertSuccessful();
        $this->type = ProductType::factory()->create();
        $this->tax = TaxClass::factory()->create();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function productData(): array
    {
        return [
            'product_type_id' => $this->type->id,
            'translations' => [
                'hr' => ['name' => 'Majica', 'description' => 'Pamučna majica'],
                'en' => ['name' => 'T-shirt', 'description' => 'Cotton shirt'],
            ],
        ];
    }

    private function variantData(): array
    {
        return [
            'sku' => ' shirt-blue-m ',
            'price_eur' => '12,35',
            'tax_class_id' => $this->tax->id,
            'stock' => 10,
            'purchasable' => 'in_stock',
            'shippable' => true,
        ];
    }

    public function test_product_table_searches_primary_name_and_active_sku_including_drafts(): void
    {
        $shirt = app(ProductEditor::class)->save($this->productData());
        $sku = app(VariantEditor::class)->save($shirt, $this->variantData());
        $otherData = $this->productData();
        $otherData['translations']['hr']['name'] = 'Šalica';
        $otherData['translations']['en']['name'] = 'Cup';
        $cup = app(ProductEditor::class)->save($otherData);

        $table = Livewire::test(ListProducts::class);
        $table->searchTable('MAJICA')->assertCanSeeTableRecords([$shirt])->assertCanNotSeeTableRecords([$cup]);
        $table->searchTable('shirt-blue')->assertCanSeeTableRecords([$shirt])->assertCanNotSeeTableRecords([$cup]);
        $table->searchTable('šALICA')->assertCanSeeTableRecords([$cup])->assertCanNotSeeTableRecords([$shirt]);
        $table->searchTable('T-shirt')->assertCanNotSeeTableRecords([$shirt, $cup]);
        $table->searchTable('shirt-blue')->filterTable('status', 'published')->assertCanNotSeeTableRecords([$shirt, $cup]);
        $table->resetTableFilters();
        $sku->delete();
        $table->searchTable('shirt-blue')->assertCanNotSeeTableRecords([$shirt, $cup]);
    }

    public function test_product_table_covers_use_only_public_catalogue_images_and_safe_demo_fallbacks(): void
    {
        config(['media-library.queue_conversions_by_default' => false]);
        Storage::fake('public');
        Storage::fake('private');
        $product = app(ProductEditor::class)->save($this->productData());
        $private = $product->addMedia(UploadedFile::fake()->image('PRIVATE-COVER.jpg'))
            ->toMediaCollection(config('lunar.media.collection'), 'private');
        $foreign = $product->addMedia(UploadedFile::fake()->image('FOREIGN-COLLECTION.jpg'))
            ->toMediaCollection('private-gallery', 'public');
        $private->update(['order_column' => 0]);
        $foreign->update(['order_column' => 0]);
        $publicAlt = 'Opis "javne" <naslovne> & podatak';
        $public = app(ProductImages::class)->add($product, UploadedFile::fake()->image('cover.jpg'), [
            'title' => 'Javna naslovna', 'alt' => ['hr' => $publicAlt, 'en' => 'Public cover'],
        ]);
        $fallback = app(ProductEditor::class)->save($this->productData());
        $attributes = $fallback->getAttribute('attribute_data');
        $attributes->put('oneshop_demo_illustration', new Text('bag'));
        $fallback->update(['attribute_data' => $attributes]);

        $this->assertTrue($public->hasGeneratedConversion('small'));
        Livewire::test(ListProducts::class)->assertSuccessful()
            ->assertSee($public->getUrl('small'), false)->assertSee(e($publicAlt), false)
            ->assertDontSee('alt="'.$publicAlt.'"', false)
            ->assertSee('/images/demo/bag.svg', false)
            ->assertDontSee('PRIVATE-COVER')->assertDontSee('FOREIGN-COLLECTION');
        $public->setCustomProperty('oneshop.alt.hr', '0')->save();
        Livewire::test(ListProducts::class)->assertSee('alt="0"', false);
    }

    public function test_product_table_search_treats_sql_wildcards_as_literal_characters(): void
    {
        $plain = app(ProductEditor::class)->save($this->productData());
        $data = $this->productData();
        $data['translations']['hr']['name'] = 'Test 50%_! ponuda';
        $special = app(ProductEditor::class)->save($data);
        Livewire::test(ListProducts::class)->searchTable('50%_!')
            ->assertCanSeeTableRecords([$special])->assertCanNotSeeTableRecords([$plain])
            ->searchTable('%%')->assertCanNotSeeTableRecords([$special, $plain]);
    }

    public function test_variant_search_keeps_underscore_literal_and_respects_the_product_and_archive_filters(): void
    {
        $product = app(ProductEditor::class)->save($this->productData());
        $literal = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'MATCH_UNDERSCORE']);
        $plain = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'MATCHXUNDERSCORE']);
        $archived = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'ARCHIVED_UNDERSCORE']);
        $archived->delete();
        $foreign = ProductVariant::factory()->create(['sku' => 'FOREIGN_UNDERSCORE']);

        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->searchTable('_')->assertCanSeeTableRecords([$literal])->assertCanNotSeeTableRecords([$plain, $archived, $foreign])
            ->searchTable('%')->assertCanNotSeeTableRecords([$literal, $plain, $archived, $foreign])
            ->searchTable('match_')->assertCanSeeTableRecords([$literal])->assertCanNotSeeTableRecords([$plain])
            ->searchTable('matchx')->assertCanSeeTableRecords([$plain])->assertCanNotSeeTableRecords([$literal])
            ->searchTable('_')->filterTable('trashed', false)->assertCanSeeTableRecords([$archived])
            ->assertCanNotSeeTableRecords([$literal, $plain, $foreign]);
    }

    public function test_variant_table_displays_only_unambiguous_unit_base_eur_prices_without_changing_tiers(): void
    {
        $product = app(ProductEditor::class)->save($this->productData());
        $normal = app(VariantEditor::class)->save($product, [...$this->variantData(), 'sku' => 'PRICE-NORMAL']);
        $zero = app(VariantEditor::class)->save($product, [...$this->variantData(), 'sku' => 'PRICE-ZERO', 'price_eur' => '0']);
        $missing = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'PRICE-MISSING']);
        $ambiguous = app(VariantEditor::class)->save($product, [...$this->variantData(), 'sku' => 'PRICE-AMBIGUOUS']);
        $ambiguous->prices()->sole()->replicate()->save();
        $pack = app(VariantEditor::class)->save($product, [...$this->variantData(), 'sku' => 'PRICE-PACK']);
        $pack->update(['unit_quantity' => 2]);
        $base = $normal->prices()->sole();
        $base->replicate()->fill(['min_quantity' => 10, 'price' => 100])->save();
        $group = CustomerGroup::factory()->create(['default' => false]);
        $base->replicate()->fill(['customer_group_id' => $group->id, 'price' => 200])->save();
        $dollar = Currency::query()->create(['code' => 'USD', 'name' => 'US dollar', 'exchange_rate' => 1, 'decimal_places' => 2, 'default' => false, 'enabled' => true]);
        $base->replicate()->fill(['currency_id' => $dollar->id, 'price' => 300])->save();
        $before = Price::query()->orderBy('id')->get()->map->getRawOriginal()->all();

        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->assertTableColumnStateSet('base_price_eur', '12,35 EUR', $normal)
            ->assertTableColumnStateSet('base_price_eur', '0,00 EUR', $zero)
            ->assertTableColumnStateSet('base_price_eur', 'Nije postavljena', $missing)
            ->assertTableColumnStateSet('base_price_eur', 'Provjeri cijene', $ambiguous)
            ->assertTableColumnStateSet('base_price_eur', 'Posebno pakiranje', $pack)
            ->assertSee('Cijena bez poreza')->assertSee('12,35 EUR')->assertSee('0,00 EUR')
            ->assertDontSee('1,00 EUR')->assertDontSee('2,00 EUR')->assertDontSee('3,00 EUR');
        config(['lunar.pricing.stored_inclusive_of_tax' => true]);
        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->assertSee('Cijena s porezom')->assertTableColumnStateSet('base_price_eur', '12,35 EUR', $normal);
        Currency::query()->where('code', 'EUR')->sole()->update(['decimal_places' => 3]);
        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->assertTableColumnStateSet('base_price_eur', 'Provjeri valutu', $normal);
        $this->assertSame($before, Price::query()->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    public function test_administrator_creates_and_edits_a_draft_through_filament(): void
    {
        $this->get('/admin/products')->assertOk();
        $this->get('/admin/products/create')->assertOk();
        Livewire::test(CreateProduct::class)
            ->fillForm($this->productData())
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->sole();
        $this->assertSame('draft', $product->status);
        $this->assertSame('Majica', $product->translateAttribute('name', 'hr'));
        $this->assertSame('T-shirt', $product->translateAttribute('name', 'en'));
        $this->get('/admin/products/'.$product->id.'/edit')->assertOk();
        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->assertFormSet($this->productData())
            ->fillForm(['translations.hr.name' => 'Nova majica'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Nova majica', $product->fresh()->translateAttribute('name', 'hr'));
    }

    public function test_missing_primary_name_cannot_create_a_product(): void
    {
        $data = $this->productData();
        $data['translations']['hr']['name'] = '';
        Livewire::test(CreateProduct::class)->fillForm($data)->call('create')
            ->assertHasFormErrors(['translations.hr.name' => 'required']);
        $this->assertDatabaseCount((new Product)->getTable(), 0);
    }

    public function test_editing_preserves_unmanaged_attributes_and_disabled_translations(): void
    {
        $product = Product::factory()->create(['attribute_data' => collect([
            'name' => new TranslatedText(['hr' => 'Staro', 'de' => 'Hemd']),
            'material' => new Text('cotton'),
        ])]);
        $data = $this->productData();
        $data['translations']['en']['name'] = '';
        $data['status'] = 'published';
        app(ProductEditor::class)->save($data, $product);
        $fresh = $product->fresh();
        $this->assertSame('Hemd', $fresh->translateAttribute('name', 'de'));
        $this->assertSame('cotton', $fresh->attribute_data->get('material')->getValue());
        $this->assertSame($product->status, $fresh->status);
        $this->assertSame('', ProductEditor::translations($fresh)['en']['name']);
    }

    public function test_variant_modal_saves_exact_price_and_scoped_edits(): void
    {
        $product = app(ProductEditor::class)->save($this->productData());
        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->callTableAction(CreateAction::class, data: $this->variantData())
            ->assertHasNoTableActionErrors();
        $variant = $product->variants()->sole();
        $this->assertSame('SHIRT-BLUE-M', $variant->sku);
        $this->assertSame(1235, $variant->prices()->sole()->price->value);
        $this->assertSame(1, $variant->unit_quantity);
        $this->assertSame('draft', $product->fresh()->status);

        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->mountTableAction(EditAction::class, $variant)
            ->assertTableActionDataSet(['price_eur' => '12.35', 'expected_stock' => 10])
            ->setTableActionData(['price_eur' => '0.29', 'stock' => 8])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();
        $this->assertSame(8, $variant->fresh()->stock);
        $this->assertSame(29, $variant->prices()->sole()->price->value);
    }

    public function test_variant_modal_displays_duplicate_and_stale_stock_errors_on_the_fields(): void
    {
        $product = app(ProductEditor::class)->save($this->productData());
        $variant = app(VariantEditor::class)->save($product, $this->variantData());
        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->callTableAction(CreateAction::class, data: $this->variantData())
            ->assertHasTableActionErrors(['sku']);

        $component = Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->mountTableAction(EditAction::class, $variant);
        $variant->update(['stock' => 9]);
        $component->setTableActionData(['stock' => 20])->callMountedTableAction()
            ->assertHasTableActionErrors(['stock']);
        $this->assertSame(9, $variant->fresh()->stock);
    }

    public function test_existing_pack_quantities_are_not_silently_repriced_as_single_units(): void
    {
        $product = app(ProductEditor::class)->save($this->productData());
        $variant = app(VariantEditor::class)->save($product, $this->variantData());
        $variant->update(['unit_quantity' => 100]);
        $this->expectException(ValidationException::class);
        app(VariantEditor::class)->save($product, VariantEditor::formData($variant), $variant);
    }

    public function test_missing_currency_does_not_leave_a_partially_created_variant(): void
    {
        $product = app(ProductEditor::class)->save($this->productData());
        Currency::query()->update(['enabled' => false]);
        try {
            app(VariantEditor::class)->save($product, $this->variantData());
            $this->fail('A variant was created without an enabled currency.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('price_eur', $exception->errors());
        }
        $this->assertSame(0, $product->variants()->count());
        $this->assertSame(0, Price::query()->count());
    }

    public function test_duplicate_sku_is_rejected_without_leaving_a_variant_or_price(): void
    {
        $first = app(ProductEditor::class)->save($this->productData());
        $second = app(ProductEditor::class)->save($this->productData());
        app(VariantEditor::class)->save($first, $this->variantData());
        try {
            app(VariantEditor::class)->save($second, $this->variantData());
            $this->fail('Duplicate SKU was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sku', $exception->errors());
        }
        $this->assertSame(0, $second->variants()->count());
        $this->assertSame(1, Price::query()->count());
    }

    public function test_database_blocks_case_and_whitespace_duplicates_including_archived_variants(): void
    {
        $product = app(ProductEditor::class)->save($this->productData());
        $variant = app(VariantEditor::class)->save($product, $this->variantData());
        $variant->delete();
        $this->expectException(UniqueConstraintViolationException::class);
        DB::transaction(fn () => ProductVariant::factory()->create([
            'product_id' => $product->id, 'tax_class_id' => $this->tax->id, 'sku' => ' shirt-blue-m ',
        ]));
    }

    public function test_stale_stock_edit_is_rejected_without_changing_price(): void
    {
        $product = app(ProductEditor::class)->save($this->productData());
        $variant = app(VariantEditor::class)->save($product, $this->variantData());
        $data = VariantEditor::formData($variant);
        $variant->update(['stock' => 9]);
        $data['stock'] = 20;
        $data['price_eur'] = '999';
        try {
            app(VariantEditor::class)->save($product, $data, $variant);
            $this->fail('A stale stock edit was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('stock', $exception->errors());
        }
        $this->assertSame(9, $variant->fresh()->stock);
        $this->assertSame(1235, $variant->prices()->sole()->price->value);
    }

    public function test_variant_from_another_product_cannot_be_reassigned(): void
    {
        $first = app(ProductEditor::class)->save($this->productData());
        $second = app(ProductEditor::class)->save($this->productData());
        $variant = app(VariantEditor::class)->save($first, $this->variantData());
        $this->expectException(ModelNotFoundException::class);
        app(VariantEditor::class)->save($second, VariantEditor::formData($variant), $variant);
    }

    public function test_invalid_prices_are_rejected_before_writing(): void
    {
        $product = app(ProductEditor::class)->save($this->productData());
        foreach (['12.345', '-1', '1e3', '1,234.56', '21474836.48'] as $invalid) {
            $data = $this->variantData();
            $data['price_eur'] = $invalid;
            try {
                app(VariantEditor::class)->save($product, $data);
                $this->fail('Invalid price accepted: '.$invalid);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('price_eur', $exception->errors());
            }
        }
        $this->assertSame(0, $product->variants()->count());
    }

    public function test_ambiguous_base_prices_roll_back_variant_changes_and_preserve_tier_prices(): void
    {
        $product = app(ProductEditor::class)->save($this->productData());
        $variant = app(VariantEditor::class)->save($product, $this->variantData());
        $base = $variant->prices()->sole();
        $tier = $base->replicate();
        $tier->fill(['min_quantity' => 10, 'price' => 1000])->save();
        app(VariantEditor::class)->save($product, [...VariantEditor::formData($variant), 'price_eur' => '15'], $variant);
        $this->assertSame(1000, $tier->fresh()->price->value);
        $base->replicate()->save();
        try {
            app(VariantEditor::class)->save($product, [...VariantEditor::formData($variant), 'stock' => 2], $variant);
            $this->fail('Ambiguous base prices were accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('price_eur', $exception->errors());
        }
        $this->assertSame(10, $variant->fresh()->stock);
    }

    public function test_customers_cannot_read_or_write_catalog_administration(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer)->get('/admin/products')->assertForbidden();
        Livewire::test(CreateProduct::class)->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(ProductEditor::class)->save($this->productData());
    }

    public function test_administrator_without_mfa_is_redirected_and_cannot_write_through_service(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->get('/admin/products')->assertRedirect(
            Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl()
        );
        $this->expectException(AuthorizationException::class);
        app(ProductEditor::class)->save($this->productData());
    }
}
