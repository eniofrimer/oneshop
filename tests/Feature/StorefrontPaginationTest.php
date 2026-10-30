<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lunar\Models\ProductVariant;
use Tests\Support\PaginatedCatalog;
use Tests\TestCase;

class StorefrontPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_are_stable_preserve_filters_and_recover_when_the_last_page_becomes_empty(): void
    {
        app()->setLocale('hr');
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
        $ids = PaginatedCatalog::seed();
        $query = '/?q=QA%20Stranica&available=1';
        $first = $this->get($query)->assertOk()->assertSee('13 proizvoda')
            ->assertSee('Prikazuje se 1–12 od 13 proizvoda.')->assertDontSee('Na ovoj stranici nema proizvoda.')
            ->assertSee('QA Stranica 01')->assertSee('QA Stranica 12')->assertDontSee('QA Stranica 13');
        $this->assertSame(array_slice($ids, 0, 12), $this->productIds($first->getContent()));
        $this->assertStringContainsString('q=QA%20Stranica&amp;available=1&amp;page=2', $first->getContent());
        $second = $this->get($query.'&page=2')->assertOk()->assertSee('13 proizvoda')
            ->assertSee('QA Stranica 13')->assertDontSee('QA Stranica 01');
        $this->assertSame([$ids[12]], $this->productIds($second->getContent()));
        $json = $this->getJson($query.'&page=2')->assertOk()->assertJsonPath('count', 13);
        $this->assertSame([$ids[12]], $this->productIds($json->json('html')));

        ProductVariant::query()->where('sku', 'QA-PAGE-13')->update(['stock' => 0]);
        $this->get($query.'&page=2')->assertOk()->assertSee('12 proizvoda')
            ->assertSee('Ova stranica kolekcije je prazna.')->assertSee('Vrati se na prvu stranicu')
            ->assertDontSee('Još nema podudaranja.');
        $this->getJson($query.'&page=2')->assertOk()->assertJsonPath('count', 12);
        $this->get($query)->assertOk()->assertSee('QA Stranica 01')->assertDontSee('QA Stranica 13');
        $this->get('/?q=QA%20Stranica')->assertOk()->assertSee('13 proizvoda');
    }

    /** @return list<int> */
    private function productIds(string $html): array
    {
        preg_match_all('~/proizvodi/([1-9][0-9]*)~', $html, $matches);

        return array_values(array_unique(array_map(intval(...), $matches[1])));
    }
}
