<?php

use App\Modules\Products\Controllers\PriceListController;
use App\Modules\Products\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::apiResource('price-lists', PriceListController::class)
    ->only(['index', 'store', 'update', 'destroy'])
    ->parameters(['price-lists' => 'priceList']);
Route::get('products/{product}/price', [ProductController::class, 'price']);
Route::get('products/{product}/prices', [ProductController::class, 'prices']);
Route::get('products/{product}/price-history', [ProductController::class, 'priceHistory']);
Route::put('products/{product}/prices', [ProductController::class, 'syncPrices']);
Route::patch('products/{product}/categories', [ProductController::class, 'syncCategories']);
Route::patch('products/{product}/tags', [ProductController::class, 'syncTags']);
Route::apiResource('products', ProductController::class);

require __DIR__.'/routes_catalog.php';
