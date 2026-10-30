<?php

namespace Tests\Support;

use App\Catalog\CatalogTranslations;
use Illuminate\Support\Facades\DB;
use Lunar\Models\Currency;
use Lunar\Models\Price;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use RuntimeException;

class PaginatedCatalog
{
    /** @return list<int> */
    public static function seed(): array
    {
        if (ProductVariant::withTrashed()->where('sku', 'like', 'QA-PAGE-%')->exists()) {
            throw new RuntimeException('The pagination fixture already exists; existing data was preserved.');
        }
        $source = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $original = $source->product;
        $currency = Currency::query()->where('code', 'EUR')->sole();

        return DB::transaction(function () use ($source, $original, $currency): array {
            $ids = [];
            for ($number = 1; $number <= 13; $number++) {
                $product = Product::query()->create([
                    'product_type_id' => $original->product_type_id, 'status' => 'published',
                    'attribute_data' => CatalogTranslations::merge($original, ['hr' => [
                        'name' => sprintf('QA Stranica %02d', $number), 'description' => 'Sintetički proizvod za provjeru stranica kataloga.',
                    ]]),
                ]);
                $variant = ProductVariant::query()->create([
                    'product_id' => $product->id, 'tax_class_id' => $source->tax_class_id,
                    'sku' => sprintf('QA-PAGE-%02d', $number), 'stock' => 10, 'backorder' => 0,
                    'unit_quantity' => 1, 'min_quantity' => 1, 'quantity_increment' => 1,
                    'purchasable' => 'in_stock', 'shippable' => true,
                ]);
                Price::query()->create([
                    'priceable_type' => $variant->getMorphClass(), 'priceable_id' => $variant->id,
                    'currency_id' => $currency->id, 'customer_group_id' => null,
                    'min_quantity' => 1, 'price' => 800,
                ]);
                $ids[] = $product->id;
            }

            return $ids;
        });
    }
}
