<?php

use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CatalogController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\GuestOrdersController;
use App\Http\Controllers\Storefront\StripeSandboxCheckoutController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CatalogController::class, 'index'])->middleware('throttle:storefront-search')->name('store.home');
Route::get('/proizvodi/{productId}', [CatalogController::class, 'show'])->where('productId', '[1-9][0-9]{0,8}')->name('store.product');
Route::get('/kosarica', [CartController::class, 'index'])->name('store.cart');
Route::get('/moje-narudzbe', GuestOrdersController::class)->name('store.orders');
Route::post('/kosarica', [CartController::class, 'store'])->middleware('throttle:60,1,oneshop-cart-add:')->name('store.cart.add');
Route::patch('/kosarica/{line}', [CartController::class, 'update'])->where('line', '[1-9][0-9]{0,8}')->name('store.cart.update');
Route::delete('/kosarica/{line}', [CartController::class, 'destroy'])->where('line', '[1-9][0-9]{0,8}')->name('store.cart.remove');

Route::get('/checkout', [CheckoutController::class, 'index'])->name('store.checkout');
Route::post('/checkout/adresa', [CheckoutController::class, 'address'])->middleware('throttle:30,1,oneshop-checkout-address:')->name('store.checkout.address');
Route::get('/checkout/pregled', [CheckoutController::class, 'review'])->name('store.checkout.review');
Route::post('/checkout/dostava', [CheckoutController::class, 'shipping'])->name('store.checkout.shipping');
Route::post('/checkout/narudzba', [CheckoutController::class, 'place'])->middleware('throttle:15,1,oneshop-checkout-place:')->name('store.checkout.place');
Route::get('/checkout/test/{checkoutToken}', [CheckoutController::class, 'payment'])->whereUuid('checkoutToken')->name('store.checkout.payment');
Route::post('/checkout/test/{checkoutToken}', [CheckoutController::class, 'simulate'])->whereUuid('checkoutToken')->middleware('throttle:30,1,oneshop-checkout-simulate:')->name('store.checkout.simulate');

Route::prefix('checkout/stripe')->name('store.stripe')->group(function (): void {
    Route::get('/', [StripeSandboxCheckoutController::class, 'index']);
    Route::post('/adresa', [StripeSandboxCheckoutController::class, 'address'])->middleware('throttle:30,1,oneshop-stripe-address:')->name('.address');
    Route::get('/pregled', [StripeSandboxCheckoutController::class, 'review'])->name('.review');
    Route::post('/dostava', [StripeSandboxCheckoutController::class, 'shipping'])->name('.shipping');
    Route::post('/narudzba', [StripeSandboxCheckoutController::class, 'place'])->middleware('throttle:15,1,oneshop-stripe-place:')->name('.place');
    Route::get('/{attemptToken}/return', [StripeSandboxCheckoutController::class, 'result'])->whereUuid('attemptToken')->name('.return');
    Route::post('/{attemptToken}/launch', [StripeSandboxCheckoutController::class, 'launch'])->whereUuid('attemptToken')
        ->middleware('throttle:15,1,oneshop-stripe-launch:')->name('.launch');
    Route::post('/{attemptToken}/refresh', [StripeSandboxCheckoutController::class, 'refresh'])->whereUuid('attemptToken')
        ->middleware('throttle:30,1,oneshop-stripe-refresh:')->name('.refresh');
    Route::post('/{attemptToken}/cancel', [StripeSandboxCheckoutController::class, 'cancel'])->whereUuid('attemptToken')
        ->middleware('throttle:10,1,oneshop-stripe-cancel:')->name('.cancel');
});
