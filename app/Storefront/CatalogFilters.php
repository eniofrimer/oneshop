<?php

namespace App\Storefront;

use App\Catalog\ProductSearch;
use App\Inventory\StockAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Lunar\Models\Product;

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
            app(ProductSearch::class)->apply($query, $filters['q']);
        }
        if ($filters['available']) {
            $basePrice = fn ($prices) => $prices->whereNull('customer_group_id')->where('min_quantity', 1)
                ->whereHas('currency', fn ($currency) => $currency->where('code', 'EUR')->where('enabled', true)->where('decimal_places', 2));
            $query->whereHas('variants', fn ($variant) => StockAvailability::whereAvailable($variant)->where('unit_quantity', 1)->where('min_quantity', 1)->where('quantity_increment', 1)
                ->whereHas('prices', $basePrice, '=', 1)
                ->whereHas('prices', fn ($prices) => $basePrice($prices)->where('price', '>=', 0)));
        }

        return $query;
    }
}
