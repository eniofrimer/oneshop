<?php

namespace App\Http\Controllers\Storefront;

use App\Storefront\CatalogFilters;
use App\Storefront\StoreCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController
{
    public function index(Request $request, StoreCatalog $catalog, CatalogFilters $filtering): View|JsonResponse
    {
        $filters = $filtering->input($request);
        $products = $filtering->apply($catalog->products(), $filters)->paginate(12)->withQueryString();
        $data = ['products' => $products, 'cards' => $products->getCollection()->map($catalog->presentation(...)), 'filters' => $filters];
        if ($request->expectsJson()) {
            return response()->json(['html' => view('storefront.catalog-results', $data)->render(), 'count' => $products->total()])
                ->header('Cache-Control', 'private, no-store')->header('Vary', 'Accept');
        }

        return view('storefront.catalog', $data);
    }

    public function show(StoreCatalog $catalog, int $productId): View
    {
        $record = $catalog->products()->findOrFail($productId);

        $item = $catalog->presentation($record);

        return view('storefront.product', ['item' => $item, 'selection' => $catalog->selection($record, $item['variants']), 'gallery' => $catalog->gallery($record)]);
    }
}
