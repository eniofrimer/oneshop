<?php

namespace Tests\Feature;

use App\Catalog\ProductEditor;
use App\Filament\Resources\Products\ProductResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Livewire\GlobalSearch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Tests\TestCase;

class AdminGlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.faker_locale' => 'en_US']);
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function owner(): User
    {
        $owner = User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
        $this->actingAs($owner);

        return $owner;
    }

    public function test_global_search_finds_primary_product_names_and_active_skus_including_drafts(): void
    {
        $this->owner();
        $cup = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole()->product;
        $cup->update(['status' => 'draft']);
        $this->assertTrue(ProductResource::canGloballySearch());
        $results = ProductResource::getGlobalSearchResults('DEMO-CUP');
        $this->assertCount(1, $results);
        $this->assertSame('Šalica Jutro', $results->sole()->title);
        $this->assertSame('Nacrt', $results->sole()->details['Status']);
        $this->assertSame(ProductResource::getUrl('edit', ['record' => $cup]), $results->sole()->url);
        Livewire::test(GlobalSearch::class)->set('search', 'šalica')->assertSee('Šalica Jutro');
        ProductVariant::query()->where('sku', 'DEMO-CUP')->sole()->delete();
        $this->assertCount(0, ProductResource::getGlobalSearchResults('DEMO-CUP'));
    }

    public function test_global_search_bounds_input_and_preserves_literal_sql_wildcards(): void
    {
        $this->owner();
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $variant->update(['sku' => 'DEMO_LITERAL_%']);
        $this->assertCount(1, ProductResource::getGlobalSearchResults('LITERAL_%'));
        $this->assertCount(0, ProductResource::getGlobalSearchResults('LITERAL_X'));
        foreach (['x', str_repeat('a', 61), "\xff\xfe"] as $invalid) {
            $this->assertCount(0, ProductResource::getGlobalSearchResults($invalid));
        }
    }

    public function test_global_search_returns_at_most_twenty_results_and_escapes_product_names(): void
    {
        $this->owner();
        $type = Product::query()->firstOrFail()->product_type_id;
        for ($number = 0; $number < 23; $number++) {
            app(ProductEditor::class)->save(['product_type_id' => $type, 'translations' => ['hr' => [
                'name' => 'Global search fixture '.$number, 'description' => '',
            ]], 'option_ids' => [], 'collection_ids' => []]);
        }
        $this->assertCount(20, ProductResource::getGlobalSearchResults('Global search fixture'));
        $product = Product::query()->firstOrFail();
        $product->update(['attribute_data' => collect(['name' => new TranslatedText(['hr' => '<script>searchMarker</script>'])])]);
        Livewire::test(GlobalSearch::class)->set('search', 'searchMarker')
            ->assertSee('&lt;script&gt;searchMarker&lt;/script&gt;', false)->assertDontSee('<script>searchMarker</script>', false);
    }

    public function test_unauthorized_and_revoked_users_cannot_search_catalogue_data(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer);
        $this->assertFalse(ProductResource::canGloballySearch());
        Livewire::test(GlobalSearch::class)->set('search', 'Šalica')->assertDontSee('Šalica Jutro');
        $this->get('/admin/products')->assertForbidden();
        $owner = $this->owner();
        $widget = Livewire::test(GlobalSearch::class)->set('search', 'Šalica')->assertSee('Šalica Jutro');
        $owner->forceFill(['is_admin' => false, 'is_super_admin' => false])->save();
        $widget->call('$refresh')->assertDontSee('Šalica Jutro');
        $this->expectException(AuthorizationException::class);
        ProductResource::getGlobalSearchResults('Šalica');
    }
}
