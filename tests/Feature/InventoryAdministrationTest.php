<?php

namespace Tests\Feature;

use App\Catalog\CatalogTranslations;
use App\Catalog\ProductSearch;
use App\Filament\Resources\Inventory\InventoryResource;
use App\Filament\Resources\Inventory\Pages\ListInventory;
use App\Filament\Resources\Products\ProductResource;
use App\Inventory\StockAvailability;
use App\Models\StockReservation;
use App\Models\User;
use App\Storefront\StoreCatalog;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Order;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Tests\TestCase;

class InventoryAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.faker_locale' => 'en_US']);
        app()->setLocale('hr');
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = User::factory()->create();
        $owner->forceFill(['is_admin' => true, 'is_super_admin' => true])->save();
        $this->actingAs($owner);
    }

    private function reserve(ProductVariant $variant, int $quantity, string $state = StockReservation::HELD): StockReservation
    {
        $order = Order::factory()->create(['currency_code' => 'EUR', 'placed_at' => now(),
            'meta' => ['oneshop_mode' => 'demo', 'private' => 'PRIVATE_INVENTORY_ORDER_DATA']]);

        return StockReservation::query()->forceCreate(['reservation_key' => (string) Str::uuid(),
            'order_id' => $order->id, 'product_variant_id' => $variant->id, 'quantity' => $quantity, 'state' => $state,
            'released_at' => $state === StockReservation::RELEASED ? now() : null,
            'consumed_at' => $state === StockReservation::CONSUMED ? now() : null]);
    }

    public function test_scope_includes_draft_skus_but_excludes_archived_skus_and_deleted_products_without_writing(): void
    {
        $all = ProductVariant::query()->get();
        $archived = $all->firstWhere('sku', 'DEMO-SHIRT-2-2');
        $archived->delete();
        $hidden = $all->firstWhere('sku', 'DEMO-BAG');
        $hidden->product->delete();
        $draft = $all->firstWhere('sku', 'DEMO-CUP');
        $draft->product->update(['status' => 'draft']);
        $before = ProductVariant::withTrashed()->orderBy('id')->get()->map->getRawOriginal()->all();
        Livewire::test(ListInventory::class)->assertCanSeeTableRecords($all->filter(fn ($record) => ! in_array($record->id, [$archived->id, $hidden->id])))
            ->assertCanSeeTableRecords([$draft])->assertCanNotSeeTableRecords([$archived, $hidden])->assertCountTableRecords(4);
        $this->assertSame($before, ProductVariant::withTrashed()->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    public function test_held_quantities_use_the_authoritative_states_without_showing_capabilities_or_order_metadata(): void
    {
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $variant->update(['stock' => 5]);
        $hold = $this->reserve($variant, 2);
        $this->reserve($variant, 3, StockReservation::CONSUMED);
        $this->reserve($variant, 4, StockReservation::RELEASED);
        $before = StockReservation::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $rows = InventoryResource::getEloquentQuery()->get()->keyBy('id');
        $this->assertSame(2, (int) $rows[$variant->id]->getAttribute('oneshop_held_quantity'));
        $this->assertSame(3, StockAvailability::available($rows[$variant->id]));
        Livewire::test(ListInventory::class)->assertTableColumnStateSet('available', '3', $variant)
            ->assertDontSee($hold->reservation_key)->assertDontSee('PRIVATE_INVENTORY_ORDER_DATA');
        $this->assertSame($before, StockReservation::query()->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    public function test_availability_filters_include_holds_and_unlimited_mode_and_zero_delivery_filter(): void
    {
        $blocked = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $blocked->update(['stock' => 2]);
        $this->reserve($blocked, 2);
        $unlimited = ProductVariant::query()->where('sku', 'DEMO-BAG')->sole();
        $unlimited->update(['stock' => 0, 'purchasable' => 'always', 'shippable' => false]);
        Livewire::test(ListInventory::class)->filterTable('availability', 'unavailable')
            ->assertCanSeeTableRecords([$blocked])->assertCanNotSeeTableRecords([$unlimited])->assertCountTableRecords(1);
        Livewire::test(ListInventory::class)->filterTable('availability', 'available')
            ->assertCanSeeTableRecords([$unlimited])->assertCanNotSeeTableRecords([$blocked])
            ->assertTableColumnStateSet('available', 'Bez ograničenja', $unlimited);
        Livewire::test(ListInventory::class)->filterTable('shippable', '0')->assertCanSeeTableRecords([$unlimited])->assertCountTableRecords(1);
        $blocked->product->update(['status' => 'draft']);
        Livewire::test(ListInventory::class)->filterTable('product_status', 'draft')->assertCanSeeTableRecords([$blocked])->assertCountTableRecords(1);
    }

    public function test_search_matches_only_its_sku_or_primary_product_name_with_literal_wildcards_and_zero(): void
    {
        $variant = ProductVariant::query()->where('sku', 'DEMO-SHIRT-1-1')->sole();
        $siblings = $variant->product->variants()->whereKeyNot($variant->id)->get();
        Livewire::test(ListInventory::class)->searchTable('demo-shirt-1-1')
            ->assertCanSeeTableRecords([$variant])->assertCanNotSeeTableRecords($siblings)->assertCountTableRecords(1);
        $attributes = $variant->product->attribute_data;
        $attributes->put('name', new TranslatedText(['hr' => '100%_! Primarni naziv', 'en' => 'FOREIGN_NAME_ONLY']));
        $variant->product->update(['attribute_data' => $attributes]);
        Livewire::test(ListInventory::class)->searchTable('%_!')->assertCountTableRecords(4);
        Livewire::test(ListInventory::class)->searchTable('FOREIGN_NAME_ONLY')->assertCountTableRecords(0);
        $attributes->put('name', new TranslatedText(['hr' => 'Primarni naziv', 'en' => 'FOREIGN_NAME_ONLY']));
        $variant->product->update(['attribute_data' => $attributes]);
        $variant->update(['sku' => '0']);
        Livewire::test(ListInventory::class)->searchTable('0')->assertCanSeeTableRecords([$variant])->assertCountTableRecords(1);
    }

    public function test_shared_search_bounds_invalid_input_without_query_errors(): void
    {
        foreach ([str_repeat('a', 256), "\xff\xfe"] as $invalid) {
            $this->assertSame(0, app(ProductSearch::class)->applyToVariants(InventoryResource::getEloquentQuery(), $invalid)->count());
            $this->assertSame(0, app(ProductSearch::class)->apply(Product::query(), $invalid)->count());
        }
    }

    public function test_access_requires_catalogue_permission_and_is_rechecked_on_livewire_refresh(): void
    {
        Auth::logout();
        $this->get('/admin/inventory')->assertRedirect('/admin/login');
        Livewire::test(ListInventory::class)->assertForbidden();
        $customer = User::factory()->create();
        $this->actingAs($customer);
        $this->get('/admin/inventory')->assertForbidden();
        $administrator = User::factory()->create();
        $administrator->forceFill(['is_admin' => true])->save();
        $this->actingAs($administrator);
        Livewire::test(ListInventory::class)->assertForbidden();
        $administrator->saveAppAuthenticationSecret(AppAuthentication::make()->generateSecret());
        $page = Livewire::test(ListInventory::class)->assertOk();
        $administrator->forceFill(['is_admin' => false])->save();
        $page->call('$refresh')->assertForbidden();
    }

    public function test_even_owner_has_no_inventory_mutation_routes_and_rows_link_to_product_editor(): void
    {
        $variant = ProductVariant::query()->firstOrFail();
        $page = Livewire::test(ListInventory::class);
        $this->assertFalse(InventoryResource::canCreate());
        $this->assertFalse(InventoryResource::canEdit($variant));
        $this->assertFalse(InventoryResource::canDelete($variant));
        $this->assertFalse(InventoryResource::canDeleteAny());
        $this->assertSame(ProductResource::getUrl('edit', ['record' => $variant->product]), $page->instance()->getTable()->getRecordUrl($variant));
        $this->get('/admin/inventory/create')->assertNotFound();
        $this->get('/admin/inventory/'.$variant->id.'/edit')->assertNotFound();
        $this->get('/admin')->assertOk()->assertSee('/admin/inventory', false);
    }

    public function test_more_skus_do_not_multiply_quantity_or_option_queries(): void
    {
        $variant = ProductVariant::query()->where('sku', 'DEMO-SHIRT-1-1')->sole();
        $variant->product->update(['status' => 'draft']);
        $measure = function (): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            foreach (InventoryResource::getEloquentQuery()->limit(50)->get() as $record) {
                StockAvailability::available($record);
                StoreCatalog::optionLabel($record);
                CatalogTranslations::formData($record->product);
            }
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $small = $measure();
        for ($number = 0; $number < 25; $number++) {
            $copy = ProductVariant::query()->create(['product_id' => $variant->product_id, 'tax_class_id' => $variant->tax_class_id,
                'sku' => 'INVENTORY-QUERY-'.$number, 'stock' => 10, 'purchasable' => 'in_stock', 'shippable' => true,
                'unit_quantity' => 1, 'min_quantity' => 1, 'quantity_increment' => 1]);
            $copy->values()->sync($variant->values->modelKeys());
        }
        $this->assertLessThanOrEqual($small + 1, $measure());
    }
}
