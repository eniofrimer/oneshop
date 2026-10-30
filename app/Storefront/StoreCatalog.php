<?php

namespace App\Storefront;

use App\Catalog\CatalogTranslations;
use App\Catalog\ProductImages;
use App\Catalog\ProductOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Lunar\DataTypes\Price as PriceData;
use Lunar\FieldTypes\Text;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Price;
use Lunar\Models\Product;
use Lunar\Models\ProductOptionValue;
use Lunar\Models\ProductVariant;

class StoreCatalog
{
    public function currency(): Currency
    {
        $currency = Currency::query()->where('code', 'EUR')->where('enabled', true)->where('decimal_places', 2)->first();
        if (! $currency) {
            throw ValidationException::withMessages(['cart' => 'Valuta trgovine trenutačno nije dostupna.']);
        }

        return $currency;
    }

    public function channel(): Channel
    {
        return Channel::query()->where('default', true)->sole();
    }

    /** @return Builder<Product> */
    public function products(): Builder
    {
        $channel = Channel::query()->where('default', true)->first();
        $group = CustomerGroup::query()->where('default', true)->first();
        $query = Product::query()->where('status', 'published');
        if (! $channel || ! $group) {
            return $query->whereRaw('1 = 0');
        }
        $pivot = (new Product)->customerGroups()->getTable();

        return $query->channel($channel)->whereHas('customerGroups', function ($query) use ($group, $pivot): void {
            $query->where("$pivot.customer_group_id", $group->id)
                ->where("$pivot.enabled", true)->where("$pivot.visible", true)->where("$pivot.purchasable", true)
                ->where(fn ($q) => $q->whereNull("$pivot.starts_at")->orWhere("$pivot.starts_at", '<=', now()))
                ->where(fn ($q) => $q->whereNull("$pivot.ends_at")->orWhere("$pivot.ends_at", '>=', now()));
        })->whereHas('variants')->orderBy('id');
    }

    public function purchasable(int $id): ProductVariant
    {
        $variant = ProductVariant::query()->find($id);
        if (! $variant) {
            throw ValidationException::withMessages(['cart' => 'Ovaj artikl više nije dostupan.']);
        }
        if (! $this->products()->whereKey($variant->product_id)->exists() || ! $variant->isPurchasable()) {
            throw ValidationException::withMessages(['cart' => 'Ovaj proizvod više nije dostupan za kupnju.']);
        }
        $this->basePrice($variant);
        if ($variant->unit_quantity !== 1 || $variant->min_quantity !== 1 || $variant->quantity_increment !== 1) {
            throw ValidationException::withMessages(['cart' => 'Ovaj proizvod još nije dostupan u internetskoj trgovini.']);
        }

        return $variant;
    }

    public function basePrice(ProductVariant $variant): Price
    {
        $prices = Price::query()->whereMorphedTo('priceable', $variant)->where('currency_id', $this->currency()->id)
            ->whereNull('customer_group_id')->where('min_quantity', 1)->get();
        if ($prices->count() !== 1 || (int) $prices->first()->getRawOriginal('price') < 0) {
            throw ValidationException::withMessages(['cart' => 'Cijena proizvoda trenutačno nije dostupna.']);
        }

        return $prices->first();
    }

    public static function money(int $minor): string
    {
        return number_format($minor / 100, 2, ',', '.').' €';
    }

    public static function optionLabel(ProductVariant $variant): string
    {
        return $variant->values->sortBy('product_option_id')->map(function ($value): string {
            if (! $value instanceof ProductOptionValue) {
                throw new \LogicException('Unsupported option value model.');
            }

            return trim($value->translate('name', config('oneshop.primary_locale')));
        })->filter(fn (string $value): bool => $value !== '')->implode(' / ');
    }

    public static function demoImage(Product $product): ?string
    {
        $attributes = $product->getAttribute('attribute_data');
        $illustration = $attributes instanceof Collection ? $attributes->get('oneshop_demo_illustration') : null;

        return $illustration instanceof Text && in_array($illustration->getValue(), ['shirt', 'cup', 'bag'], true)
            ? asset('images/demo/'.$illustration->getValue().'.svg') : null;
    }

    /** @return array{id: int, name: string, description: string, image: ?string, image_srcset: ?string, image_alt: string, variants: Collection<int, array{id: int, sku: string, label: string, price: int, available: bool}>} */
    public function presentation(Product $product): array
    {
        $translations = CatalogTranslations::formData($product)[config('oneshop.primary_locale')];
        $image = ProductImages::query($product)->first();
        $sources = $image ? ProductImages::storefrontSources($image) : null;
        $variants = [];
        $currency = Currency::query()->where('code', 'EUR')->where('enabled', true)->where('decimal_places', 2)->first();
        $records = ProductVariant::query()->where('product_id', $product->id)->where('unit_quantity', 1)
            ->where('min_quantity', 1)->where('quantity_increment', 1)
            ->with(['values.option', 'taxClass.taxRateAmounts'])->get();
        $prices = $currency ? Price::query()->where('priceable_type', (new ProductVariant)->getMorphClass())
            ->whereIn('priceable_id', $records->modelKeys())->where('currency_id', $currency->id)
            ->whereNull('customer_group_id')->where('min_quantity', 1)->get()->groupBy('priceable_id') : collect();
        foreach ($records as $variant) {
            $base = $prices->get($variant->id);
            if (! $currency || ! $base || $base->count() !== 1 || (int) $base->first()->getRawOriginal('price') < 0) {
                continue;
            }
            $priceRecord = $base->first();
            $priceRecord->setRelation('priceable', $variant)->setRelation('currency', $currency);
            $price = $priceRecord->priceIncTax();
            $label = self::optionLabel($variant);
            $minor = $price instanceof PriceData ? $price->value : $price;
            if (! is_int($minor)) {
                throw new \LogicException('Expected integer EUR minor units.');
            }

            $variants[] = [
                'id' => $variant->id, 'sku' => $variant->sku ?? '',
                'label' => $label === '' ? 'Standard' : $label,
                'price' => $minor,
                'available' => $variant->purchasable === 'always' || $variant->stock > 0,
            ];
        }

        return ['id' => $product->id, 'name' => $translations['name'], 'description' => $translations['description'],
            'image' => $sources['preview'] ?? self::demoImage($product), 'image_srcset' => $sources['srcset'] ?? null,
            'image_alt' => ($image ? trim((string) $image->getCustomProperty('oneshop.alt.'.config('oneshop.primary_locale'), '')) : '') ?: $translations['name'],
            'variants' => collect($variants)];
    }

    /**
     * @param  ?Collection<int, array{id: int, sku: string, label: string, price: int, available: bool}>  $presented
     * @return array{options: list<array{id: int, name: string, display: string, values: list<array{id: int, name: string}>}>, variants: list<array{id: int, values: array<int, int>, available: bool, price: int}>}
     */
    public function selection(Product $product, ?Collection $presented = null): array
    {
        $options = [];
        $variants = [];
        $presented = ($presented ?? $this->presentation($product)['variants'])->keyBy('id');
        $active = ProductVariant::query()->where('product_id', $product->id)->whereIn('id', $presented->keys())->with('values')->get();
        foreach (ProductOptions::forProduct($product) as $option) {
            $values = ProductOptionValue::query()->where('product_option_id', $option->id)
                ->whereHas('variants', fn ($query) => $query->whereIn((new ProductVariant)->getQualifiedKeyName(), $active->modelKeys()))
                ->orderBy('position')->orderBy('id')->get();
            $options[] = ['id' => $option->id, 'name' => $option->translate('name', config('oneshop.primary_locale')),
                'display' => data_get($option->getAttribute('meta'), 'oneshop.display') === 'radio' ? 'radio' : 'dropdown',
                'values' => $values->map(fn (ProductOptionValue $value) => ['id' => $value->id, 'name' => $value->translate('name', config('oneshop.primary_locale'))])->all()];
        }
        foreach ($active as $variant) {
            $display = $presented->get($variant->id);
            if (! $display) {
                continue;
            }
            $values = $variant->values->sortBy('product_option_id')->mapWithKeys(function ($value): array {
                if (! $value instanceof ProductOptionValue) {
                    throw new \LogicException('Unsupported option value model.');
                }

                return [$value->product_option_id => $value->id];
            })->all();
            $variants[] = ['id' => $variant->id, 'values' => $values, 'available' => $display['available'], 'price' => $display['price']];
        }

        return ['options' => $options, 'variants' => $variants];
    }

    /** @return list<array{url: string, thumbnail: string, alt: string}> */
    public function gallery(Product $product): array
    {
        return ProductImages::query($product)->get()->map(function ($image): array {
            $sources = ProductImages::storefrontSources($image);

            return ['url' => $sources['original'], 'thumbnail' => $sources['thumbnail'],
                'alt' => trim((string) $image->getCustomProperty('oneshop.alt.'.config('oneshop.primary_locale'), '')) ?: $image->name];
        })->all();
    }
}
