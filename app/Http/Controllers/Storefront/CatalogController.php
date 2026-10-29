<?php

namespace App\Http\Controllers\Storefront;

use App\Storefront\StoreCatalog;
use Illuminate\Contracts\View\View;

class CatalogController
{
    public function index(StoreCatalog $catalog): View
    {
        $products = $catalog->products()->paginate(12);

        return view('storefront.catalog', ['products' => $products, 'cards' => $products->getCollection()->map($catalog->presentation(...))]);
    }

    public function show(StoreCatalog $catalog, int $productId): View
    {
        $record = $catalog->products()->findOrFail($productId);

        return view('storefront.product', ['item' => $catalog->presentation($record)]);
    }
}
