<?php

namespace App\Http\Controllers\Storefront;

use App\Storefront\CatalogFilters;
use App\Storefront\StoreCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;

class CatalogController
{
    public function index(Request $request, StoreCatalog $catalog, CatalogFilters $filtering): View|JsonResponse|Response
    {
        try {
            $filters = $filtering->input($request);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }
            // A GET must not redirect back to the same invalid query indefinitely.
            $query = $request->query('q');

            return response()->view('storefront.catalog', [
                'products' => new LengthAwarePaginator([], 0, 12, 1, ['path' => $request->url()]),
                'cards' => collect(),
                'filters' => ['q' => is_string($query) ? trim($query) : '', 'available' => $request->query('available') === '1'],
                'errors' => (new ViewErrorBag)->put('default', $exception->validator->errors()),
            ], 422)->header('Cache-Control', 'private, no-store');
        }
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
