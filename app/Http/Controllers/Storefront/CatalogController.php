<?php

namespace App\Http\Controllers\Storefront;

use App\Storefront\CatalogFilters;
use App\Storefront\StoreCatalog;
use App\Storefront\StoreSeo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;

class CatalogController
{
    public function index(Request $request, StoreCatalog $catalog, CatalogFilters $filtering, StoreSeo $seo): JsonResponse|Response
    {
        try {
            $filters = $filtering->input($request);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }
            // A GET must not redirect back to the same invalid query indefinitely.
            $query = $request->query('q');
            $sort = $request->query('sort');

            return response()->view('storefront.catalog', [
                'products' => new LengthAwarePaginator([], 0, CatalogFilters::PER_PAGE, 1, ['path' => $request->url()]),
                'cards' => collect(),
                'filters' => ['q' => is_string($query) ? trim($query) : '', 'available' => $request->query('available') === '1',
                    'sort' => is_string($sort) && array_key_exists($sort, CatalogFilters::SORT_OPTIONS) ? $sort : 'default'],
                'errors' => (new ViewErrorBag)->put('default', $exception->validator->errors()),
            ], 422)->header('Cache-Control', 'private, no-store');
        }
        $products = $filtering->apply($catalog->products(), $filters)->paginate(CatalogFilters::PER_PAGE)->withQueryString();
        $data = ['products' => $products, 'cards' => $catalog->presentations($products->getCollection()), 'filters' => $filters];
        if ($request->expectsJson()) {
            return response()->json(['html' => view('storefront.catalog-results', $data)->render(), 'count' => $products->total()])
                ->header('Cache-Control', 'private, no-store')->header('Vary', 'Accept');
        }

        $data['seo'] = $seo->catalog($products, $filters);

        return response()->view('storefront.catalog', $data)->header('X-Robots-Tag', $data['seo']['robots']);
    }

    public function show(Request $request, StoreCatalog $catalog, StoreSeo $seo, int $productId): Response
    {
        $record = $catalog->products()->findOrFail($productId);

        $item = $catalog->presentation($record);

        $metadata = $seo->product($item, $request);

        return response()->view('storefront.product', ['item' => $item, 'selection' => $catalog->selection($record, $item['variants']),
            'gallery' => $catalog->gallery($record), 'seo' => $metadata])->header('X-Robots-Tag', $metadata['robots']);
    }
}
