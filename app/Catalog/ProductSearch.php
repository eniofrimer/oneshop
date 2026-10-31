<?php

namespace App\Catalog;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;

class ProductSearch
{
    /** @param Builder<Product> $query
     * @return Builder<Product>
     */
    public function apply(Builder $query, string $search): Builder
    {
        $needle = $this->needle($search);
        if ($needle === null) {
            return $query->whereRaw('1 = 0');
        }
        $grammar = $query->getQuery()->getGrammar();
        $name = $this->nameColumn($grammar);
        $sku = $grammar->wrap((new ProductVariant)->qualifyColumn('sku'));

        return $query->where(fn ($matches) => $matches->whereRaw("LOWER($name) LIKE ? ESCAPE '!'", [$needle])
            ->orWhereHas('variants', fn ($variants) => $variants->whereRaw("LOWER($sku) LIKE ? ESCAPE '!'", [$needle])));
    }

    /** @param Builder<ProductVariant> $query
     * @return Builder<ProductVariant>
     */
    public function applyToVariants(Builder $query, string $search): Builder
    {
        $needle = $this->needle($search);
        if ($needle === null) {
            return $query->whereRaw('1 = 0');
        }
        $grammar = $query->getQuery()->getGrammar();
        $name = $this->nameColumn($grammar);
        $sku = $grammar->wrap((new ProductVariant)->qualifyColumn('sku'));

        return $query->where(fn ($matches) => $matches->whereRaw("LOWER($sku) LIKE ? ESCAPE '!'", [$needle])
            ->orWhereHas('product', fn ($products) => $products->whereRaw("LOWER($name) LIKE ? ESCAPE '!'", [$needle])));
    }

    private function nameColumn(Grammar $grammar): string
    {
        $locale = config('oneshop.primary_locale');
        abort_unless(in_array($locale, ProductEditor::locales(), true), 500);

        return $grammar->wrap((new Product)->qualifyColumn('attribute_data')."->name->value->$locale");
    }

    private function needle(string $search): ?string
    {
        $search = trim($search);
        if (strlen($search) > 1020 || ! mb_check_encoding($search, 'UTF-8') || mb_strlen($search) > 255) {
            return null;
        }

        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search, 'UTF-8')).'%';
    }
}
