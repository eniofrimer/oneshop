<?php

namespace App\Storefront;

use App\Catalog\CatalogTranslations;
use App\Catalog\ProductImages;
use App\Catalog\ProductOptions;
use App\Catalog\VariantPhotos;
use App\Inventory\StockAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** @phpstan-type CatalogCard array{id: int, name: string, description: string, image: ?string, image_srcset: ?string, image_alt: string, variants: Collection<int, array{id: int, sku: string, label: string, price: int, available: bool}>} */
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
        $variant = StockAvailability::withHeld(ProductVariant::query())->find($id);
        if (! $variant) {
            throw ValidationException::withMessages(['cart' => 'Ovaj artikl više nije dostupan.']);
        }
        if (! $this->products()->whereKey($variant->product_id)->exists() || ! StockAvailability::canSupply($variant)) {
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

    private static function demoImageKey(Product $product, ?ProductVariant $variant = null): ?string
    {
        $attributes = $product->getAttribute('attribute_data');
        $illustration = $attributes instanceof Collection ? $attributes->get('oneshop_demo_illustration') : null;

        $key = $illustration instanceof Text && in_array($illustration->getValue(), ['shirt', 'cup', 'bag'], true)
            ? $illustration->getValue() : null;
        if ($key === 'shirt' && $variant && (int) $variant->product_id === (int) $product->id
            && in_array($variant->sku, ['DEMO-SHIRT-2-1', 'DEMO-SHIRT-2-2'], true)) {
            return 'shirt-sand';
        }

        return $key;
    }

    public static function demoImage(Product $product, ?ProductVariant $variant = null): ?string
    {
        $key = self::demoImageKey($product, $variant);

        return $key === null ? null : asset('images/demo-v2/'.$key.'-800.webp');
    }

    private static function demoImageSrcset(Product $product): ?string
    {
        $key = self::demoImageKey($product);

        return $key === null ? null : implode(', ', array_map(fn (int $width) => asset('images/demo-v2/'.$key.'-'.$width.'.webp').' '.$width.'w', [480, 800, 1200]));
    }

    /** @return CatalogCard */
    public function presentation(Product $product): array
    {
        return $this->presentations(new EloquentCollection([$product]))->sole();
    }

    /**
     * @param  EloquentCollection<int, Product>  $products
     * @return Collection<int, CatalogCard>
     */
    public function presentations(EloquentCollection $products): Collection
    {
        if ($products->isEmpty()) {
            return collect();
        }
        // Limit each product's public cover independently, including imported galleries.
        $products->load(['media' => fn (MorphMany $media) => $media
            ->where('collection_name', config('lunar.media.collection'))->where('disk', 'public')
            ->orderBy('order_column')->orderBy('id')->limit(1)]);
        $currency = Currency::query()->where('code', 'EUR')->where('enabled', true)->where('decimal_places', 2)->first();
        $records = StockAvailability::withHeld(ProductVariant::query())->whereIn('product_id', $products->modelKeys())->where('unit_quantity', 1)
            ->where('min_quantity', 1)->where('quantity_increment', 1)->orderBy('id')
            ->with(['values.option', 'taxClass.taxRateAmounts'])->get();
        $prices = $currency ? Price::query()->where('priceable_type', (new ProductVariant)->getMorphClass())
            ->whereIn('priceable_id', $records->modelKeys())->where('currency_id', $currency->id)
            ->whereNull('customer_group_id')->where('min_quantity', 1)->get()->groupBy('priceable_id') : collect();
        $byProduct = $records->groupBy('product_id');

        return $products->map(function (Product $product) use ($currency, $prices, $byProduct): array {
            /** @var EloquentCollection<int, Media> $media */
            $media = $product->getRelation('media');
            $image = $media->first();
            $image?->setRelation('model', $product);

            return $this->card($product, $image, $byProduct->get($product->id, new EloquentCollection), $currency, $prices);
        });
    }

    /**
     * @param  EloquentCollection<int, ProductVariant>  $records
     * @param  Collection<int, Collection<int, Price>>  $prices
     * @return CatalogCard
     */
    private function card(Product $product, ?Media $image, EloquentCollection $records, ?Currency $currency, Collection $prices): array
    {
        $translations = CatalogTranslations::formData($product)[config('oneshop.primary_locale')];
        $sources = $image ? ProductImages::storefrontSources($image) : null;
        $variants = [];
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
                'available' => StockAvailability::canSupply($variant),
            ];
        }

        return ['id' => $product->id, 'name' => $translations['name'], 'description' => $translations['description'],
            'image' => $sources['preview'] ?? self::demoImage($product), 'image_srcset' => $sources['srcset'] ?? ($image ? null : self::demoImageSrcset($product)),
            'image_alt' => ProductImages::alternativeText($image, $translations['name']),
            'variants' => collect($variants)];
    }

    /**
     * @param  ?Collection<int, array{id: int, sku: string, label: string, price: int, available: bool}>  $presented
     * @return array{options: list<array{id: int, name: string, display: string, values: list<array{id: int, name: string}>}>, variants: list<array{id: int, values: array<int, int>, available: bool, price: int, photo: ?array{url: string, preview: string, thumbnail: string, srcset: ?string, alt: string}}>}
     */
    public function selection(Product $product, ?Collection $presented = null): array
    {
        $options = [];
        $variants = [];
        $presented = ($presented ?? $this->presentation($product)['variants'])->keyBy('id');
        $active = ProductVariant::query()->where('product_id', $product->id)->whereIn('id', $presented->keys())
            ->with(['values', 'images' => fn (BelongsToMany $images) => VariantPhotos::constrain($images)])->get();
        $hasGallery = ProductImages::query($product)->exists();
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
            $image = VariantPhotos::current($variant);
            $photo = $image ? self::photo($image) : null;
            if (! $photo && ! $hasGallery && self::demoImageKey($product) === 'shirt') {
                $key = self::demoImageKey($product, $variant) ?? 'shirt';
                $photo = self::demoPhoto($key, $key === 'shirt-sand' ? 'Ogledna majica u boji pijeska' : 'Ogledna majica u maslinastoj boji');
            }
            $variants[] = ['id' => $variant->id, 'values' => $values, 'available' => $display['available'], 'price' => $display['price'], 'photo' => $photo];
        }

        return ['options' => $options, 'variants' => $variants];
    }

    /** @return list<array{url: string, preview: string, thumbnail: string, srcset: ?string, alt: string}> */
    public function gallery(Product $product): array
    {
        $photos = ProductImages::query($product)->get()->map(self::photo(...))->all();
        if ($photos === [] && self::demoImageKey($product) === 'shirt') {
            return [self::demoPhoto('shirt', 'Ogledna majica u maslinastoj boji'),
                self::demoPhoto('shirt-sand', 'Ogledna majica u boji pijeska')];
        }

        return $photos;
    }

    /** @return array{url: string, preview: string, thumbnail: string, srcset: ?string, alt: string} */
    private static function photo(Media $image): array
    {
        $sources = ProductImages::storefrontSources($image);

        return ['url' => $sources['original'], 'preview' => $sources['preview'], 'thumbnail' => $sources['thumbnail'],
            'srcset' => $sources['srcset'], 'alt' => ProductImages::alternativeText($image, $image->name)];
    }

    /** @return array{url: string, preview: string, thumbnail: string, srcset: string, alt: string} */
    private static function demoPhoto(string $key, string $alt): array
    {
        return ['url' => asset('images/demo-v2/'.$key.'-1200.webp'), 'preview' => asset('images/demo-v2/'.$key.'-800.webp'),
            'thumbnail' => asset('images/demo-v2/'.$key.'-480.webp'), 'alt' => $alt,
            'srcset' => implode(', ', array_map(fn (int $width) => asset('images/demo-v2/'.$key.'-'.$width.'.webp').' '.$width.'w', [480, 800, 1200]))];
    }
}
