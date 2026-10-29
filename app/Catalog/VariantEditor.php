<?php

namespace App\Catalog;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Currency;
use Lunar\Models\Price;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Lunar\Models\TaxClass;

class VariantEditor
{
    /** @return array<string, mixed> */
    public static function formData(ProductVariant $variant): array
    {
        $price = self::basePriceQuery($variant)->first();
        $minor = $price?->price->value;

        return [
            'sku' => $variant->sku,
            'tax_class_id' => $variant->tax_class_id,
            'stock' => $variant->stock,
            'purchasable' => $variant->purchasable,
            'shippable' => $variant->shippable,
            'expected_stock' => $variant->stock,
            'price_eur' => $minor === null ? '' : intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT),
        ];
    }

    /** @return Builder<Price> */
    private static function basePriceQuery(ProductVariant $variant): Builder
    {
        return Price::query()
            ->where('priceable_type', $variant->getMorphClass())
            ->where('priceable_id', $variant->id)
            ->whereHas('currency', fn ($query) => $query->where('code', 'EUR'))
            ->whereNull('customer_group_id')
            ->where('min_quantity', 1);
    }

    /** @param array<string, mixed> $data */
    public function save(Product $product, array $data, ?ProductVariant $variant = null): ProductVariant
    {
        Gate::authorize('update', $product);
        $data['sku'] = is_string($data['sku'] ?? null) ? strtoupper(trim($data['sku'])) : null;
        $data['price_eur'] = is_string($data['price_eur'] ?? null) ? trim($data['price_eur']) : $data['price_eur'] ?? null;
        $validated = Validator::make($data, [
            'sku' => ['required', 'string', 'max:100', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/D'],
            'tax_class_id' => ['required', 'integer', Rule::exists(TaxClass::class, 'id')],
            'stock' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'expected_stock' => [$variant ? 'required' : 'nullable', 'integer'],
            'purchasable' => ['required', Rule::in(['in_stock', 'always'])],
            'shippable' => ['required', 'boolean'],
            'price_eur' => ['required', 'regex:/^\d{1,8}(?:[.,]\d{1,2})?$/D'],
        ])->validate();

        $parts = explode('.', str_replace(',', '.', (string) $validated['price_eur']));
        $minor = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
        if ($minor > 2147483647) {
            throw ValidationException::withMessages(['price_eur' => 'Cijena prelazi podržani raspon.']);
        }

        try {
            return DB::transaction(function () use ($product, $variant, $validated, $minor): ProductVariant {
                $parent = Product::query()->lockForUpdate()->findOrFail($product->id);
                /** @var ProductVariant $record */
                $record = $variant ? $parent->variants()->lockForUpdate()->findOrFail($variant->id) : new ProductVariant;
                if ($variant && ($record->unit_quantity !== 1 || $record->min_quantity !== 1 || $record->quantity_increment !== 1)) {
                    throw ValidationException::withMessages(['price_eur' => 'Ovaj uređivač podržava prodaju po jednom komadu. Postojeća pakiranja nisu izmijenjena.']);
                }
                if ($variant && $record->stock !== (int) $validated['expected_stock']) {
                    throw ValidationException::withMessages(['stock' => 'Zaliha je u međuvremenu promijenjena. Ponovno otvorite varijantu.']);
                }
                $currency = Currency::query()->where('code', 'EUR')->where('enabled', true)->where('decimal_places', 2)->first();
                if (! $currency) {
                    throw ValidationException::withMessages(['price_eur' => 'Najprije inicijalizirajte EUR valutu trgovine.']);
                }
                if (ProductVariant::withTrashed()->whereRaw('UPPER(TRIM(sku)) = ?', [$validated['sku']])->when($variant, fn ($query) => $query->whereKeyNot($variant->id))->exists()) {
                    throw ValidationException::withMessages(['sku' => 'SKU već postoji, uključujući arhivirane varijante.']);
                }
                $record->fill([
                    'product_id' => $parent->id,
                    'sku' => $validated['sku'],
                    'tax_class_id' => $validated['tax_class_id'],
                    'stock' => $validated['stock'],
                    'purchasable' => $validated['purchasable'],
                    'shippable' => $validated['shippable'],
                ]);
                $record->save();

                $prices = self::basePriceQuery($record)->lockForUpdate()->get();
                if ($prices->count() > 1) {
                    throw ValidationException::withMessages(['price_eur' => 'Postoji više osnovnih EUR cijena. Najprije razriješite duplikate.']);
                }
                $price = $prices->first() ?? new Price([
                    'priceable_type' => $record->getMorphClass(),
                    'priceable_id' => $record->id,
                    'currency_id' => $currency->id,
                    'customer_group_id' => null,
                    'min_quantity' => 1,
                ]);
                $price->fill(['price' => $minor]);
                $price->save();

                return $record;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'oneshop_variants_normalized_sku_unique')) {
                throw $exception;
            }
            throw ValidationException::withMessages(['sku' => 'SKU već postoji. Odaberite jedinstvenu oznaku.']);
        }
    }
}
