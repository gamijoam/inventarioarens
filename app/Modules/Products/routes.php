<?php

use App\Modules\Products\Controllers\ProductController;
use App\Modules\Products\Controllers\PriceListController;
use Illuminate\Support\Facades\Route;

Route::apiResource('price-lists', PriceListController::class)
    ->only(['index', 'store', 'update', 'destroy'])
    ->parameters(['price-lists' => 'priceList']);
Route::get('products/{product}/price', [ProductController::class, 'price']);
Route::get('products/{product}/prices', [ProductController::class, 'prices']);
Route::put('products/{product}/prices', [ProductController::class, 'syncPrices']);
Route::apiResource('products', ProductController::class);
