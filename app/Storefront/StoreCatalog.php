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
        return Currency::query()->where('code', 'EUR')->where('enabled', true)->where('decimal_places', 2)->firstOrFail();
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

    /** @return array{id: int, name: string, description: string, image: ?string, variants: Collection<int, array{id: int, sku: string, label: string, price: int, available: bool}>} */
    public function presentation(Product $product): array
    {
        $translations = CatalogTranslations::formData($product)[config('oneshop.primary_locale')];
        $image = ProductImages::query($product)->first();
        $variants = [];
        foreach (ProductVariant::query()->where('product_id', $product->id)->with('values.option')->get() as $variant) {
            try {
                $price = $this->basePrice($variant)->priceIncTax();
            } catch (ValidationException) {
                continue;
            }
            $labels = ProductOptionValue::query()->whereHas('variants', fn ($q) => $q->whereKey($variant->id))->orderBy('product_option_id')->get()->map(fn (ProductOptionValue $value) => $value->translate('name', config('oneshop.primary_locale')))->filter();
            $minor = $price instanceof PriceData ? $price->value : $price;
            if (! is_int($minor)) {
                throw new \LogicException('Expected integer EUR minor units.');
            }

            $variants[] = [
                'id' => $variant->id, 'sku' => $variant->sku ?? '',
                'label' => $labels->isEmpty() ? 'Standard' : $labels->implode(' / '),
                'price' => $minor,
                'available' => $variant->purchasable === 'always' || $variant->stock > 0,
            ];
        }

        $attributes = $product->getAttribute('attribute_data');
        $illustration = $attributes instanceof Collection ? $attributes->get('oneshop_demo_illustration') : null;
        $demoImage = $illustration instanceof Text && in_array($illustration->getValue(), ['shirt', 'cup', 'bag'], true)
            ? asset('images/demo/'.$illustration->getValue().'.svg') : null;

        return ['id' => $product->id, 'name' => $translations['name'], 'description' => $translations['description'],
            'image' => $image?->getUrl() ?? $demoImage, 'variants' => collect($variants)];
    }

    /** @return array{options: list<array{id: int, name: string, display: string, values: list<array{id: int, name: string}>}>, variants: list<array{id: int, values: array<int, int>, available: bool, price: int}>} */
    public function selection(Product $product): array
    {
        $options = [];
        $variants = [];
        $presented = $this->presentation($product)['variants']->keyBy('id');
        $active = ProductVariant::query()->where('product_id', $product->id)->whereIn('id', $presented->keys())->get();
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
            $values = ProductOptionValue::query()->whereHas('variants', fn ($query) => $query->whereKey($variant->id))->orderBy('product_option_id')->get()
                ->mapWithKeys(fn (ProductOptionValue $value) => [$value->product_option_id => $value->id])->all();
            $variants[] = ['id' => $variant->id, 'values' => $values, 'available' => $display['available'], 'price' => $display['price']];
        }

        return ['options' => $options, 'variants' => $variants];
    }

    /** @return list<array{url: string, alt: string}> */
    public function gallery(Product $product): array
    {
        return ProductImages::query($product)->get()->map(fn ($image) => ['url' => $image->getUrl(),
            'alt' => (string) $image->getCustomProperty('oneshop.alt.'.config('oneshop.primary_locale'), $image->name)])->all();
    }
}
