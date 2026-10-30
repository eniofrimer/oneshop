<?php

namespace App\Http\Controllers\Storefront;

use App\Catalog\CatalogTranslations;
use App\Catalog\ProductImages;
use App\Storefront\GuestCart;
use App\Storefront\StoreCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Lunar\Models\CartLine;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class CartController
{
    public function index(Request $request, GuestCart $carts): View
    {
        $cart = $carts->current($request->session());
        $warning = null;
        $lines = [];
        if ($cart && $cart->lines->isNotEmpty()) {
            try {
                $carts->validateLines($cart);
                $cart->recalculate();
            } catch (ValidationException $exception) {
                $warning = 'Neki artikli više nisu dostupni. Uklonite ih iz košarice za nastavak.';
            }
            $cart->loadMissing('lines.purchasable');
            $cart->lines->loadMorph('purchasable', [ProductVariant::class => ['values', 'product.media' => fn ($images) => $images
                ->where('collection_name', config('lunar.media.collection'))->where('disk', 'public')->orderBy('order_column')->orderBy('id')]]);
            foreach ($cart->lines as $line) {
                if (! $line instanceof CartLine) {
                    throw new \LogicException('Unsupported cart line model.');
                }
                $variant = $line->purchasable;
                $product = $variant instanceof ProductVariant ? $variant->getRelationValue('product') : null;
                $image = $product instanceof Product ? $product->getRelationValue('media')?->first() : null;
                $lines[] = ['id' => $line->id, 'quantity' => $line->quantity, 'sku' => $variant instanceof ProductVariant ? $variant->sku : 'Nedostupan artikl',
                    'name' => $product instanceof Product ? CatalogTranslations::formData($product)[config('oneshop.primary_locale')]['name'] : 'Nedostupan artikl',
                    'option' => $variant instanceof ProductVariant ? StoreCatalog::optionLabel($variant) : '',
                    'url' => ! $warning && $product instanceof Product ? route('store.product', $product->id) : null,
                    'image' => ! $warning && $product instanceof Product
                        ? ($image instanceof Media ? ProductImages::storefrontSources($image)['thumbnail'] : StoreCatalog::demoImage($product)) : null,
                    'total' => $warning ? null : $line->total?->value];
            }
        }

        return view('storefront.cart', compact('cart', 'lines', 'warning'));
    }

    public function store(Request $request, GuestCart $carts): RedirectResponse
    {
        $data = $request->validate(['variant_id' => ['required', 'integer', 'min:1', 'max:2147483647'], 'quantity' => ['required', 'integer', 'min:1', 'max:99']]);
        $carts->add($request->session(), (int) $data['variant_id'], (int) $data['quantity']);

        return to_route('store.cart')->with('message', 'Proizvod je dodan u košaricu.');
    }

    public function update(Request $request, GuestCart $carts, int $line): RedirectResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:99']]);
        $carts->update($request->session(), $line, (int) $data['quantity']);

        return to_route('store.cart')->with('message', 'Količina je ažurirana.');
    }

    public function destroy(Request $request, GuestCart $carts, int $line): RedirectResponse
    {
        $carts->remove($request->session(), $line);

        return to_route('store.cart')->with('message', 'Artikl je uklonjen.');
    }
}
