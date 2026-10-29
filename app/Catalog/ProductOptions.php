<?php

namespace App\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Lunar\Models\Product;
use Lunar\Models\ProductOption;

class ProductOptions
{
    /** @return Collection<int, ProductOption> */
    public static function forProduct(Product $product): Collection
    {
        return $product->productOptions()->get()->map(function (Model $option): ProductOption {
            if (! $option instanceof ProductOption) {
                throw new \LogicException('Unsupported product option model.');
            }

            return $option;
        });
    }
}
