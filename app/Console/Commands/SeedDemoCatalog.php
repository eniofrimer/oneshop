<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Country;
use Lunar\Models\Currency;
use Lunar\Models\Price;
use Lunar\Models\Product;
use Lunar\Models\ProductOption;
use Lunar\Models\ProductType;
use Lunar\Models\ProductVariant;
use Lunar\Models\TaxClass;
use Lunar\Models\TaxRate;
use Lunar\Models\TaxRateAmount;
use Lunar\Models\TaxZone;

class SeedDemoCatalog extends Command
{
    protected $signature = 'oneshop:demo-catalog';

    protected $description = 'Create an explicitly development-only, repeatable demo catalogue in an empty shop';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Demo catalogue creation is allowed only in local/testing environments.');

            return self::FAILURE;
        }
        if ($this->call('oneshop:initialize') !== self::SUCCESS) {
            return self::FAILURE;
        }
        if (ProductType::query()->where('name', 'Demo kolekcija')->exists()) {
            $this->info('Demo catalogue already exists. Products, merchant edits and stock were preserved.');

            return self::SUCCESS;
        }
        if (Product::withTrashed()->exists() || TaxZone::query()->exists() || TaxClass::query()->exists()) {
            $this->error('Demo catalogue requires an empty catalogue and no existing tax configuration. Merchant records were preserved.');

            return self::FAILURE;
        }
        DB::transaction(function (): void {
            $currency = Currency::query()->where('code', 'EUR')->sole();
            $type = ProductType::query()->create(['name' => 'Demo kolekcija']);
            $taxClass = TaxClass::query()->create(['name' => 'DEMO — testni porez 25%', 'default' => true]);
            $zone = TaxZone::query()->create(['name' => 'DEMO — Hrvatska', 'zone_type' => 'country', 'price_display' => 'tax_inclusive', 'active' => true, 'default' => true]);
            $country = Country::query()->firstOrCreate(['iso2' => 'HR'], ['name' => 'Croatia', 'iso3' => 'HRV', 'currency' => 'EUR', 'phonecode' => '385', 'native' => 'Hrvatska', 'emoji' => '🇭🇷', 'emoji_u' => 'U+1F1ED U+1F1F7', 'capital' => 'Zagreb']);
            $zone->countries()->create(['country_id' => $country->id]);
            $rate = TaxRate::query()->create(['tax_zone_id' => $zone->id, 'name' => 'DEMO 25% — nije potvrđena porezna politika', 'priority' => 1]);
            TaxRateAmount::query()->create(['tax_rate_id' => $rate->id, 'tax_class_id' => $taxClass->id, 'percentage' => 25]);
            $colour = ProductOption::query()->create(['handle' => 'demo-colour', 'name' => ['hr' => 'Boja'], 'label' => ['hr' => 'Boja'], 'shared' => true]);
            $size = ProductOption::query()->create(['handle' => 'demo-size', 'name' => ['hr' => 'Veličina'], 'label' => ['hr' => 'Veličina'], 'shared' => true]);
            $colours = [$colour->values()->create(['name' => ['hr' => 'Maslinasta'], 'position' => 1]), $colour->values()->create(['name' => ['hr' => 'Pijesak'], 'position' => 2])];
            $sizes = [$size->values()->create(['name' => ['hr' => 'M'], 'position' => 1]), $size->values()->create(['name' => ['hr' => 'L'], 'position' => 2])];
            $shirt = $this->product($type, 'Majica Osnova', 'Jednostavna majica ravnog kroja. Odaberi boju i veličinu za svoj ritam dana.\nDemo proizvod za provjeru varijabilne kupnje.', 'shirt');
            $shirt->productOptions()->sync([$colour->id => ['position' => 0], $size->id => ['position' => 1]]);
            foreach ($colours as $i => $colourValue) {
                foreach ($sizes as $j => $sizeValue) {
                    $variant = $this->variant($shirt, $taxClass, $currency, 'DEMO-SHIRT-'.($i + 1).'-'.($j + 1), 2000);
                    $variant->values()->sync([$colourValue->id, $sizeValue->id]);
                }
            }
            $cup = $this->product($type, 'Šalica Jutro', 'Topla boja i jednostavan oblik za prvu kavu. Mali ritual, svaki dan. Demo proizvod za provjeru kupnje.', 'cup');
            $this->variant($cup, $taxClass, $currency, 'DEMO-CUP', 1200);
            $bag = $this->product($type, 'Torba Svaki dan', 'Od tržnice do grada, jednostavna torba za stvari koje idu s tobom. Demo proizvod za provjeru kupnje.', 'bag');
            $this->variant($bag, $taxClass, $currency, 'DEMO-BAG', 1600);
        });
        $this->info('Demo catalogue created: 3 products, 6 SKUs, one variable product. Test tax: 25%. No real trading policy was configured.');

        return self::SUCCESS;
    }

    private function product(ProductType $type, string $name, string $description, string $illustration): Product
    {
        return Product::query()->create(['product_type_id' => $type->id, 'status' => 'published',
            'attribute_data' => collect(['name' => new TranslatedText(collect(['hr' => $name])), 'description' => new TranslatedText(collect(['hr' => str_replace('\\n', "\n", $description)])), 'oneshop_demo_illustration' => new Text($illustration)])]);
    }

    private function variant(Product $product, TaxClass $tax, Currency $currency, string $sku, int $netMinor): ProductVariant
    {
        $variant = ProductVariant::query()->create(['product_id' => $product->id, 'tax_class_id' => $tax->id, 'sku' => $sku,
            'unit_quantity' => 1, 'min_quantity' => 1, 'quantity_increment' => 1, 'stock' => 20, 'backorder' => 0, 'purchasable' => 'in_stock', 'shippable' => true]);
        $minor = config('lunar.pricing.stored_inclusive_of_tax') ? (int) round($netMinor * 1.25) : $netMinor;
        Price::query()->create(['priceable_type' => $variant->getMorphClass(), 'priceable_id' => $variant->id, 'currency_id' => $currency->id, 'customer_group_id' => null, 'min_quantity' => 1, 'price' => $minor]);

        return $variant;
    }
}
