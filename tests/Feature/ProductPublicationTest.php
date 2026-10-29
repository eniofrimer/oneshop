<?php

namespace Tests\Feature;

use App\Catalog\OptionEditor;
use App\Catalog\ProductEditor;
use App\Catalog\ProductPublication;
use App\Catalog\VariantEditor;
use App\Digital\DigitalAssetEditor;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Product;
use Lunar\Models\ProductType;
use Lunar\Models\ProductVariant;
use Lunar\Models\TaxClass;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ProductPublicationTest extends TestCase
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
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->product = app(ProductEditor::class)->save([
            'product_type_id' => ProductType::factory()->create()->id,
            'translations' => ['hr' => ['name' => 'Majica']],
        ]);
        $this->variant = app(VariantEditor::class)->save($this->product, [
            'sku' => 'SHIRT', 'price_eur' => '12.35', 'stock' => 10, 'purchasable' => 'in_stock',
            'shippable' => true, 'tax_class_id' => TaxClass::factory()->create()->id,
        ]);
    }

    private function audience(): array
    {
        return ['channel_ids' => [Channel::query()->where('default', true)->sole()->id], 'customer_group_ids' => [CustomerGroup::query()->where('default', true)->sole()->id]];
    }

    private function assertPublicationRejected(): void
    {
        try {
            app(ProductPublication::class)->publish($this->product, $this->audience());
            $this->fail('An incomplete catalog product was published.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('publication', $exception->errors());
        }
        $this->assertSame('draft', $this->product->fresh()->status);
    }

    public function test_admin_publishes_and_withdraws_a_ready_product_through_filament(): void
    {
        $this->assertSame([], app(ProductPublication::class)->issues($this->product));
        $component = Livewire::test(EditProduct::class, ['record' => $this->product->id]);
        $component->callAction('publish', data: $this->audience())->assertHasNoActionErrors()
            ->assertNotified('Proizvod je objavljen u katalogu');
        $this->assertSame('published', $this->product->fresh()->status);
        $this->assertTrue($this->variant->fresh()->isPurchasable());
        $component->callAction('withdraw')->assertNotified('Proizvod je vraćen u nacrt');
        $this->assertSame('draft', $this->product->fresh()->status);
        $this->assertFalse($this->variant->fresh()->isPurchasable());
        $this->assertSame(10, $this->variant->fresh()->stock);
        $this->assertSame(1235, $this->variant->prices()->sole()->price->value);
        $this->assertSame(['catalog-published', 'catalog-withdrawn'], Activity::query()->whereIn('event', ['catalog-published', 'catalog-withdrawn'])->orderBy('id')->pluck('event')->all());
    }

    public function test_an_unready_product_keeps_its_modal_open_and_displays_the_reason(): void
    {
        $this->variant->prices()->delete();
        Livewire::test(EditProduct::class, ['record' => $this->product->id])
            ->callAction('publish', data: $this->audience())->assertNotified('Objava nije spremljena')->assertActionHalted('publish');
        $this->assertSame('draft', $this->product->fresh()->status);
    }

    public function test_missing_skus_and_missing_primary_names_are_not_publishable(): void
    {
        $this->variant->delete();
        $this->product->update(['attribute_data' => collect()]);
        $issues = app(ProductPublication::class)->issues($this->product);
        $this->assertContains('Dodajte barem jedan SKU.', $issues);
        $this->assertContains('Nedostaje naziv na osnovnom jeziku trgovine.', $issues);
        $this->assertPublicationRejected();
    }

    public function test_group_prices_or_duplicate_base_prices_cannot_substitute_for_one_base_eur_price(): void
    {
        $price = $this->variant->prices()->sole();
        $price->update(['customer_group_id' => $this->audience()['customer_group_ids'][0]]);
        $this->assertPublicationRejected();
        $price->update(['customer_group_id' => null]);
        $price->replicate()->save();
        $this->assertPublicationRejected();
    }

    public function test_incomplete_and_duplicate_imported_option_combinations_are_rejected(): void
    {
        $option = app(OptionEditor::class)->saveOption(['names' => ['hr' => 'Boja'], 'display' => 'radio']);
        $value = app(OptionEditor::class)->saveValue($option, ['names' => ['hr' => 'Plava'], 'position' => 0]);
        app(ProductEditor::class)->save([
            'product_type_id' => $this->product->product_type_id,
            'translations' => ProductEditor::translations($this->product), 'option_ids' => [$option->id],
        ], $this->product);
        $this->assertPublicationRejected();
        $this->variant->values()->sync([$value->id]);
        $this->assertSame([], app(ProductPublication::class)->issues($this->product));
        $duplicate = $this->variant->replicate()->fill(['sku' => 'IMPORTED-DUPLICATE']);
        $duplicate->save();
        $duplicate->values()->sync([$value->id]);
        $this->variant->prices()->sole()->replicate()->fill(['priceable_id' => $duplicate->id])->save();
        $this->assertPublicationRejected();
    }

    public function test_a_multi_sku_product_requires_options_before_publication(): void
    {
        $second = $this->variant->replicate()->fill(['sku' => 'SECOND']);
        $second->save();
        $this->variant->prices()->sole()->replicate()->fill(['priceable_id' => $second->id])->save();
        $this->assertPublicationRejected();
    }

    public function test_digital_publication_requires_an_active_file_that_still_exists(): void
    {
        $this->variant->update(['shippable' => false]);
        $this->assertPublicationRejected();
        $asset = app(DigitalAssetEditor::class)->upload($this->variant, UploadedFile::fake()->createWithContent('book.pdf', 'book'), 'Knjiga');
        $this->assertSame([], app(ProductPublication::class)->issues($this->product));
        app(DigitalAssetEditor::class)->archive($asset, true);
        $this->assertPublicationRejected();
        app(DigitalAssetEditor::class)->archive($asset, false);
        Storage::disk('private')->delete($asset->path);
        $this->assertPublicationRejected();
    }

    public function test_only_selected_audiences_are_enabled_without_detaching_existing_pivot_rows(): void
    {
        $oldChannel = $this->audience()['channel_ids'][0];
        $oldGroup = $this->audience()['customer_group_ids'][0];
        $channel = Channel::factory()->create(['default' => false]);
        $group = CustomerGroup::factory()->create(['default' => false]);
        $future = now()->addYear()->startOfSecond();
        $this->product->channels()->updateExistingPivot($oldChannel, ['starts_at' => $future]);
        app(ProductPublication::class)->publish($this->product, ['channel_ids' => [$channel->id], 'customer_group_ids' => [$group->id]]);
        $this->assertSame(2, $this->product->channels()->count());
        $this->assertSame(2, $this->product->customerGroups()->count());
        $this->assertFalse((bool) $this->product->channels()->find($oldChannel)->pivot->enabled);
        $this->assertFalse((bool) $this->product->customerGroups()->find($oldGroup)->pivot->visible);
        $this->assertFalse((bool) $this->product->customerGroups()->find($oldGroup)->pivot->purchasable);
        $this->assertSame($future->toDateTimeString(), (string) $this->product->channels()->find($oldChannel)->pivot->starts_at);
        $this->assertTrue((bool) $this->product->channels()->find($channel->id)->pivot->enabled);
        $this->assertTrue((bool) $this->product->customerGroups()->find($group->id)->pivot->visible);
        $this->assertTrue((bool) $this->product->customerGroups()->find($group->id)->pivot->purchasable);
        $this->assertNull($this->product->channels()->find($channel->id)->pivot->ends_at);
    }

    public function test_empty_audiences_and_deleted_channels_are_rejected_before_writes(): void
    {
        foreach ([['channel_ids' => [], 'customer_group_ids' => []], ['channel_ids' => [999999], 'customer_group_ids' => $this->audience()['customer_group_ids']]] as $audience) {
            try {
                app(ProductPublication::class)->publish($this->product, $audience);
                $this->fail('An invalid publication audience was accepted.');
            } catch (ValidationException) {
                $this->assertSame('draft', $this->product->fresh()->status);
            }
        }
        $channel = Channel::factory()->create(['default' => false]);
        $channel->delete();
        $this->expectException(ValidationException::class);
        app(ProductPublication::class)->publish($this->product, [...$this->audience(), 'channel_ids' => [$channel->id]]);
    }

    public function test_disabled_eur_and_unsupported_pack_quantities_block_publication(): void
    {
        Currency::query()->where('code', 'EUR')->update(['enabled' => false]);
        $this->assertPublicationRejected();
        Currency::query()->where('code', 'EUR')->update(['enabled' => true]);
        $this->variant->update(['unit_quantity' => 100]);
        $this->assertPublicationRejected();
    }

    public function test_customer_and_unenrolled_admin_cannot_publish_or_withdraw(): void
    {
        $unenrolled = User::factory()->create();
        $unenrolled->forceFill(['is_admin' => true])->save();
        foreach ([User::factory()->create(), $unenrolled] as $user) {
            $this->actingAs($user);
            foreach ([fn () => app(ProductPublication::class)->publish($this->product, $this->audience()), fn () => app(ProductPublication::class)->withdraw($this->product)] as $operation) {
                try {
                    $operation();
                    $this->fail('An unauthorized user changed publication.');
                } catch (AuthorizationException) {
                    $this->assertSame('draft', $this->product->fresh()->status);
                }
            }
        }
    }

    public function test_unknown_merchant_status_is_preserved_instead_of_being_reinterpreted(): void
    {
        $this->product->update(['status' => 'legacy-hidden']);
        try {
            app(ProductPublication::class)->withdraw($this->product);
            $this->fail('An unsupported merchant status was overwritten.');
        } catch (ValidationException) {
            $this->assertSame('legacy-hidden', $this->product->fresh()->status);
        }
    }
}
