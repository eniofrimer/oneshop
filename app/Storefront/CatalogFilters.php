<?php

namespace App\Storefront;

use App\Catalog\ProductEditor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;

class CatalogFilters
{
    /** @return array{q: string, available: bool} */
    public function input(Request $request): array
    {
        foreach ($request->query() as $key => $value) {
            abort_unless(in_array($key, ['q', 'available', 'page'], true), 404);
            abort_unless($value === null || (is_string($value) && strlen($value) <= 4096 && mb_check_encoding($value, 'UTF-8')), 400);
        }
        $data = $request->validate(['q' => ['nullable', 'string', 'min:2', 'max:60'],
            'available' => ['nullable', 'in:0,1'], 'page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return ['q' => trim($data['q'] ?? ''), 'available' => ($data['available'] ?? '0') === '1'];
    }

    /** @param Builder<Product> $query
     * @param  array{q: string, available: bool}  $filters
     * @return Builder<Product>
     */
    public function apply(Builder $query, array $filters): Builder
    {
        if ($filters['q'] !== '') {
            $locale = config('oneshop.primary_locale');
            abort_unless(in_array($locale, ProductEditor::locales(), true), 500);
            $grammar = $query->getQuery()->getGrammar();
            $name = $grammar->wrap((new Product)->qualifyColumn('attribute_data')."->name->value->$locale");
            $sku = $grammar->wrap((new ProductVariant)->qualifyColumn('sku'));
            $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['q'], 'UTF-8')).'%';
            $query->where(fn ($search) => $search->whereRaw("LOWER($name) LIKE ? ESCAPE '!'", [$needle])
                ->orWhereHas('variants', fn ($variants) => $variants->whereRaw("LOWER($sku) LIKE ? ESCAPE '!'", [$needle])));
        }
        if ($filters['available']) {
            $basePrice = fn ($prices) => $prices->whereNull('customer_group_id')->where('min_quantity', 1)
                ->whereHas('currency', fn ($currency) => $currency->where('code', 'EUR')->where('enabled', true)->where('decimal_places', 2));
            $query->whereHas('variants', fn ($variant) => $variant->where('unit_quantity', 1)->where('min_quantity', 1)->where('quantity_increment', 1)
                ->where(fn ($stock) => $stock->where('purchasable', 'always')->orWhere(fn ($tracked) => $tracked->where('purchasable', 'in_stock')->where('stock', '>', 0)))
                ->whereHas('prices', $basePrice, '=', 1)
                ->whereHas('prices', fn ($prices) => $basePrice($prices)->where('price', '>=', 0)));
        }

        return $query;
    }
}
