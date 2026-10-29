<?php

use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CatalogController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CatalogController::class, 'index'])->name('store.home');
Route::get('/proizvodi/{productId}', [CatalogController::class, 'show'])->where('productId', '[1-9][0-9]{0,8}')->name('store.product');
Route::get('/kosarica', [CartController::class, 'index'])->name('store.cart');
Route::post('/kosarica', [CartController::class, 'store'])->middleware('throttle:60,1')->name('store.cart.add');
Route::patch('/kosarica/{line}', [CartController::class, 'update'])->where('line', '[1-9][0-9]{0,8}')->name('store.cart.update');
Route::delete('/kosarica/{line}', [CartController::class, 'destroy'])->where('line', '[1-9][0-9]{0,8}')->name('store.cart.remove');
