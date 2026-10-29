<?php

namespace App\Catalog;

use Illuminate\Database\Eloquent\Builder;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;

class ProductSearch
{
    /** @param Builder<Product> $query
     * @return Builder<Product>
     */
    public function apply(Builder $query, string $search): Builder
    {
        $locale = config('oneshop.primary_locale');
        abort_unless(in_array($locale, ProductEditor::locales(), true), 500);
        $grammar = $query->getQuery()->getGrammar();
        $name = $grammar->wrap((new Product)->qualifyColumn('attribute_data')."->name->value->$locale");
        $sku = $grammar->wrap((new ProductVariant)->qualifyColumn('sku'));
        $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($search), 'UTF-8')).'%';

        return $query->where(fn ($matches) => $matches->whereRaw("LOWER($name) LIKE ? ESCAPE '!'", [$needle])
            ->orWhereHas('variants', fn ($variants) => $variants->whereRaw("LOWER($sku) LIKE ? ESCAPE '!'", [$needle])));
    }
}
