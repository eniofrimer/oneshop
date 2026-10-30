<?php

use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CatalogController;
use App\Http\Controllers\Storefront\CheckoutController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CatalogController::class, 'index'])->middleware('throttle:storefront-search')->name('store.home');
Route::get('/proizvodi/{productId}', [CatalogController::class, 'show'])->where('productId', '[1-9][0-9]{0,8}')->name('store.product');
Route::get('/kosarica', [CartController::class, 'index'])->name('store.cart');
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
