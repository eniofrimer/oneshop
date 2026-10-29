<?php

namespace Tests\Feature;

use App\Catalog\CatalogTranslations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Price;
use Lunar\Models\ProductVariant;
use Tests\TestCase;

class StorefrontSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('hr');
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
    }

    public function test_name_and_sku_search_return_public_results_as_html_or_json(): void
    {
        $this->get('/?q=MAJICA')->assertOk()->assertSee('Majica Osnova')->assertDontSee('Torba Svaki dan');
        $this->get('/?q=šALICA')->assertOk()->assertSee('Šalica Jutro')->assertDontSee('Majica Osnova');
        $response = $this->getJson('/?q=demo-cup')->assertOk()->assertJsonPath('count', 1);
        $this->assertStringContainsString('Šalica Jutro', $response->json('html'));
        $this->assertStringNotContainsString('Torba Svaki dan', $response->json('html'));
        $this->assertStringNotContainsString('<html', $response->json('html'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get('/?q=does-not-exist')->assertOk()->assertSee('Još nema podudaranja.');
        $this->get('/?q=')->assertOk()->assertSee('Majica Osnova')->assertSee('Torba Svaki dan');
    }

    public function test_like_wildcards_are_literal_and_cannot_match_the_entire_catalog(): void
    {
        $this->getJson('/?q=%25%25')->assertOk()->assertJsonPath('count', 0);
        $this->getJson('/?q=__')->assertOk()->assertJsonPath('count', 0);
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $variant->product->update(['attribute_data' => CatalogTranslations::merge($variant->product, ['hr' => ['name' => 'Test 50%_! ponuda', 'description' => '']])]);
        $this->getJson('/?q=50%25_%21')->assertOk()->assertJsonPath('count', 1);
    }

    public function test_search_never_counts_or_exposes_drafts_hidden_or_archived_skus(): void
    {
        $cup = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $cup->product->update(['status' => 'draft']);
        $this->getJson('/?q=demo-cup')->assertOk()->assertJsonPath('count', 0);
        $cup->product->update(['status' => 'published']);
        $group = CustomerGroup::query()->where('default', true)->sole();
        $cup->product->customerGroups()->updateExistingPivot($group->id, ['visible' => false]);
        $this->getJson('/?q=demo-cup')->assertOk()->assertJsonPath('count', 0);
        $cup->product->customerGroups()->updateExistingPivot($group->id, ['visible' => true]);
        $cup->delete();
        $this->getJson('/?q=demo-cup')->assertOk()->assertJsonPath('count', 0);
    }

    public function test_availability_filter_matches_supported_stock_and_unambiguous_eur_prices(): void
    {
        $cup = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $cup->update(['stock' => 0]);
        $this->getJson('/?available=1')->assertOk()->assertJsonPath('count', 2);
        $cup->update(['purchasable' => 'always']);
        $this->getJson('/?available=1')->assertOk()->assertJsonPath('count', 3);
        Price::query()->whereMorphedTo('priceable', $cup)->delete();
        $this->getJson('/?available=1')->assertOk()->assertJsonPath('count', 2);
        $this->getJson('/?q=salica&available=1')->assertOk()->assertJsonPath('count', 0);
    }

    public function test_query_shape_length_and_page_are_bounded_and_invalid_utf8_is_rejected(): void
    {
        $this->getJson('/?q=a')->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/?q='.str_repeat('a', 61))->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/?q[]=test')->assertStatus(400);
        $this->getJson('/?q=%FF%FF')->assertStatus(400);
        $this->getJson('/?page=101')->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->getJson('/?available=maybe')->assertUnprocessable()->assertJsonValidationErrors('available');
        $this->get('/?unknown=anything')->assertNotFound();
    }

    public function test_repeated_search_requests_are_throttled(): void
    {
        $last = null;
        for ($i = 0; $i < 60; $i++) {
            $last = $this->getJson('/?q=no-match');
            if ($last->status() === 429) {
                break;
            }
            $last->assertOk();
        }
        $this->assertSame(429, $last->status());
        $this->get('/?q=no-match')->assertStatus(429)->assertHeader('Retry-After')->assertSee('Pričekaj trenutak.');
        $this->get('/')->assertOk();
    }

    public function test_native_invalid_search_displays_errors_without_a_redirect_loop(): void
    {
        $this->withHeader('Referer', url('/?q=x'))->get('/?q=x')->assertStatus(422)
            ->assertSee('Provjerite unos')->assertSee('value="x"', false)->assertSee('catalog-q-error', false)
            ->assertHeaderMissing('Location')->assertDontSee('validation.min');
        $this->get('/?page=101')->assertStatus(422)->assertSee('Provjerite unos')->assertHeaderMissing('Location');
        $this->get('/?q=majica')->assertOk()->assertSee('Majica Osnova')->assertDontSee('Provjerite unos');
    }
}
