<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;
use Lunar\Models\Brand;
use Lunar\Models\Product;
use Tests\TestCase;

class IncrementalMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.faker_locale' => 'en_US']);
        Storage::fake('local');
        $this->artisan('oneshop:initialize')->assertSuccessful();
    }

    public function test_migrating_an_initialized_shop_again_does_not_require_legacy_brand_files(): void
    {
        $this->assertFalse(Storage::disk('local')->exists('tmp/state/legacy_brands.json'));
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertSame(0, Brand::query()->count());
    }

    public function test_a_real_legacy_brand_file_is_still_processed(): void
    {
        $product = Product::factory()->create(['brand_id' => null]);
        Storage::disk('local')->put('tmp/state/legacy_brands.json', json_encode(['Legacy brand' => [$product->id]]));
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $brand = Brand::query()->where('name', 'Legacy brand')->sole();
        $this->assertSame($brand->id, $product->fresh()->brand_id);
        $this->assertFalse(Storage::disk('local')->exists('tmp/state/legacy_brands.json'));
    }

    public function test_the_dedicated_paid_file_disk_still_throws_on_missing_content(): void
    {
        Storage::fake('private');
        $this->expectException(UnableToReadFile::class);
        Storage::disk('private')->get('does-not-exist');
    }
}
