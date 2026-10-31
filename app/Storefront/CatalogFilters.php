<?php

namespace App\Storefront;

use App\Catalog\ProductSearch;
use App\Inventory\StockAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Lunar\Models\Product;

class CatalogFilters
{
    public const int PER_PAGE = 12;

    public const int MAX_PAGE = 100;

    public const array SORT_OPTIONS = ['default' => 'Zadani redoslijed', 'newest' => 'Najnovije prvo',
        'name-asc' => 'Po nazivu, uzlazno', 'name-desc' => 'Po nazivu, silazno'];

    /** @return array{q: string, available: bool, sort: string} */
    public function input(Request $request): array
    {
        foreach ($request->query() as $key => $value) {
            abort_unless(in_array($key, ['q', 'available', 'page', 'sort'], true), 404);
            abort_unless($value === null || (is_string($value) && strlen($value) <= 4096 && mb_check_encoding($value, 'UTF-8')), 400);
        }
        $data = $request->validate(['q' => ['nullable', 'string', 'min:2', 'max:60'],
            'available' => ['nullable', 'in:0,1'], 'page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PAGE],
            'sort' => ['nullable', 'in:'.implode(',', array_keys(self::SORT_OPTIONS))]]);

        return ['q' => trim($data['q'] ?? ''), 'available' => ($data['available'] ?? '0') === '1', 'sort' => $data['sort'] ?? 'default'];
    }

    /** @param Builder<Product> $query
     * @param  array{q: string, available: bool, sort?: string}  $filters
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

        return match ($filters['sort'] ?? 'default') {
            'name-asc' => app(ProductSearch::class)->orderByName($query),
            'name-desc' => app(ProductSearch::class)->orderByName($query, true),
            'newest' => $query->reorder()
                ->orderByRaw('CASE WHEN '.(new Product)->qualifyColumn('created_at').' IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc((new Product)->qualifyColumn('created_at'))->orderByDesc((new Product)->getQualifiedKeyName()),
            default => $query,
        };
    }
}
