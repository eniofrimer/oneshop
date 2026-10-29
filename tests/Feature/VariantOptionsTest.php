<?php

namespace Tests\Feature;

use App\Catalog\OptionEditor;
use App\Catalog\ProductEditor;
use App\Catalog\VariantEditor;
use App\Filament\Resources\Options\Pages\CreateOption;
use App\Filament\Resources\Options\Pages\EditOption;
use App\Filament\Resources\Options\RelationManagers\ValuesRelationManager;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Lunar\Models\Price;
use Lunar\Models\Product;
use Lunar\Models\ProductOption;
use Lunar\Models\ProductOptionValue;
use Lunar\Models\ProductType;
use Lunar\Models\ProductVariant;
use Lunar\Models\TaxClass;
use Tests\TestCase;

class VariantOptionsTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductOption $color;

    private ProductOption $size;

    private ProductOptionValue $blue;

    private ProductOptionValue $medium;

    private TaxClass $tax;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.faker_locale' => 'en_US']);
        config(['oneshop.enabled_locales' => ['hr', 'en']]);
        $this->artisan('oneshop:initialize')->assertSuccessful();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true, 'is_super_admin' => true])->save();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $editor = app(OptionEditor::class);
        $this->color = $editor->saveOption(['names' => ['hr' => 'Boja', 'en' => 'Color'], 'display' => 'radio']);
        $this->size = $editor->saveOption(['names' => ['hr' => 'Veličina', 'en' => 'Size'], 'display' => 'dropdown']);
        $this->blue = $editor->saveValue($this->color, ['names' => ['hr' => 'Plava', 'en' => 'Blue'], 'position' => 0]);
        $this->medium = $editor->saveValue($this->size, ['names' => ['hr' => 'Srednja', 'en' => 'Medium'], 'position' => 0]);
        $this->tax = TaxClass::factory()->create();
        $this->product = app(ProductEditor::class)->save([
            'product_type_id' => ProductType::factory()->create()->id,
            'translations' => ['hr' => ['name' => 'Majica'], 'en' => ['name' => 'Shirt']],
            'option_ids' => [$this->color->id, $this->size->id],
        ]);
    }

    private function variantData(): array
    {
        return [
            'sku' => 'SHIRT-BLUE-M', 'price_eur' => '12.35', 'stock' => 10,
            'tax_class_id' => $this->tax->id, 'purchasable' => 'in_stock', 'shippable' => true,
            'option_values' => [$this->color->id => $this->blue->id, $this->size->id => $this->medium->id],
        ];
    }

    public function test_options_and_translated_values_can_be_prepared_through_filament(): void
    {
        $this->get('/admin/options')->assertOk();
        Livewire::test(CreateOption::class)
            ->fillForm(['names' => ['hr' => 'Materijal', 'en' => 'Material'], 'display' => 'radio'])
            ->call('create')->assertHasNoFormErrors();
        $option = ProductOption::query()->latest('id')->firstOrFail();
        $this->assertTrue($option->shared);
        $this->assertSame('Materijal', $option->translate('name', 'hr'));
        $this->get('/admin/options/'.$option->id.'/edit')->assertOk();
        Livewire::test(EditOption::class, ['record' => $option->id])
            ->assertFormSet(['names.hr' => 'Materijal', 'display' => 'radio'])
            ->fillForm(['names.en' => 'Fabric'])->call('save')->assertHasNoFormErrors();
        Livewire::test(ValuesRelationManager::class, ['ownerRecord' => $option, 'pageClass' => EditOption::class])
            ->callTableAction(CreateAction::class, data: ['names' => ['hr' => 'Pamuk', 'en' => 'Cotton'], 'position' => 1])
            ->assertHasNoTableActionErrors();
        $this->assertSame('Fabric', $option->fresh()->translate('name', 'en'));
        $this->assertSame('Pamuk', $option->values()->sole()->translate('name', 'hr'));
    }

    public function test_product_form_loads_and_reorders_selected_options_without_losing_translations(): void
    {
        $this->get('/admin/products/'.$this->product->id.'/edit')->assertOk();
        Livewire::test(EditProduct::class, ['record' => $this->product->id])
            ->assertFormSet(['option_ids' => [$this->color->id, $this->size->id]])
            ->fillForm(['option_ids' => [$this->size->id, $this->color->id], 'translations.hr.name' => 'Nova majica'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame([$this->size->id, $this->color->id], $this->product->productOptions()->get()->pluck('id')->all());
        $this->assertSame('Nova majica', $this->product->fresh()->translateAttribute('name', 'hr'));
        $this->assertSame('Shirt', $this->product->fresh()->translateAttribute('name', 'en'));
    }

    public function test_sku_form_stores_exactly_one_value_for_each_product_option(): void
    {
        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $this->product, 'pageClass' => EditProduct::class])
            ->callTableAction(CreateAction::class, data: $this->variantData())->assertHasNoTableActionErrors();
        $variant = $this->product->variants()->sole();
        $this->assertEqualsCanonicalizing([$this->blue->id, $this->medium->id], $variant->values->pluck('id')->all());
        $this->assertSame(1235, $variant->prices()->sole()->price->value);
        $this->assertSame($this->variantData()['option_values'], VariantEditor::formData($variant)['option_values']);
    }

    public function test_a_duplicate_combination_is_rejected_even_with_a_different_sku(): void
    {
        app(VariantEditor::class)->save($this->product, $this->variantData());
        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $this->product, 'pageClass' => EditProduct::class])
            ->callTableAction(CreateAction::class, data: [...$this->variantData(), 'sku' => 'DUPLICATE-SKU'])
            ->assertHasTableActionErrors(['option_values']);
        $this->assertSame(1, $this->product->variants()->count());
        $this->assertSame(1, Price::query()->count());
    }

    public function test_an_existing_combination_can_be_edited_and_other_combinations_can_be_added(): void
    {
        $variant = app(VariantEditor::class)->save($this->product, $this->variantData());
        app(VariantEditor::class)->save($this->product, [...VariantEditor::formData($variant), 'stock' => 8], $variant);
        $large = app(OptionEditor::class)->saveValue($this->size, ['names' => ['hr' => 'Velika'], 'position' => 1]);
        $data = $this->variantData();
        $data['sku'] = 'SHIRT-BLUE-L';
        $data['option_values'][$this->size->id] = $large->id;
        app(VariantEditor::class)->save($this->product, $data);
        $this->assertSame(2, $this->product->variants()->count());
        $this->assertSame(8, $variant->fresh()->stock);
    }

    public function test_a_value_from_another_option_is_rejected_without_writes(): void
    {
        $data = $this->variantData();
        $data['option_values'][$this->color->id] = $this->medium->id;
        try {
            app(VariantEditor::class)->save($this->product, $data);
            $this->fail('A value belonging to another option was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('option_values.'.$this->color->id, $exception->errors());
        }
        $this->assertSame(0, ProductVariant::query()->count());
        $this->assertSame(0, Price::query()->count());
    }

    public function test_missing_and_extra_values_are_rejected(): void
    {
        foreach ([[], [$this->color->id => $this->blue->id], [...$this->variantData()['option_values'], 9999 => $this->blue->id]] as $values) {
            try {
                app(VariantEditor::class)->save($this->product, [...$this->variantData(), 'option_values' => $values]);
                $this->fail('An incomplete or unknown option set was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertSame(0, $this->product->variants()->count());
    }

    public function test_an_option_used_by_skus_cannot_be_silently_removed(): void
    {
        app(VariantEditor::class)->save($this->product, $this->variantData());
        $this->expectException(ValidationException::class);
        app(ProductEditor::class)->save([
            'product_type_id' => $this->product->product_type_id,
            'translations' => ProductEditor::translations($this->product),
            'option_ids' => [$this->size->id],
        ], $this->product);
    }

    public function test_new_options_cannot_be_added_to_a_published_product_with_live_skus(): void
    {
        app(VariantEditor::class)->save($this->product, $this->variantData());
        $this->product->update(['status' => 'published']);
        $extra = app(OptionEditor::class)->saveOption(['names' => ['hr' => 'Kroj'], 'display' => 'radio']);
        $this->expectException(ValidationException::class);
        app(ProductEditor::class)->save([
            'product_type_id' => $this->product->product_type_id,
            'translations' => ProductEditor::translations($this->product),
            'option_ids' => [$this->size->id, $this->color->id, $extra->id],
        ], $this->product);
    }

    public function test_option_edits_preserve_disabled_languages_and_unmanaged_metadata(): void
    {
        $this->color->update(['name' => ['hr' => 'Boja', 'de' => 'Farbe'], 'meta' => ['external' => 'preserve', 'oneshop' => ['custom' => 'keep']]]);
        app(OptionEditor::class)->saveOption(['names' => ['hr' => 'Nova boja', 'en' => ''], 'display' => 'radio'], $this->color);
        $fresh = $this->color->fresh();
        $this->assertSame('Farbe', $fresh->translate('name', 'de'));
        $this->assertSame('', OptionEditor::formData($fresh)['names']['en']);
        $this->assertSame('preserve', data_get($fresh->meta, 'external'));
        $this->assertSame('keep', data_get($fresh->meta, 'oneshop.custom'));
    }

    public function test_a_value_cannot_be_reassigned_by_forging_its_parent(): void
    {
        $this->expectException(ModelNotFoundException::class);
        app(OptionEditor::class)->saveValue($this->size, ['names' => ['hr' => 'Prepisano'], 'position' => 1], $this->blue);
    }

    public function test_customer_cannot_read_or_write_shared_options(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/options')->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(OptionEditor::class)->saveOption(['names' => ['hr' => 'Napad'], 'display' => 'radio']);
    }
}
