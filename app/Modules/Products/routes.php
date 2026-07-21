<?php

use App\Modules\Products\Controllers\PriceListController;
use App\Modules\Products\Controllers\ProductController;
use App\Modules\Products\Controllers\ProductImageController;
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

// Imagenes propias de producto (galeria multi-imagen, Nivel 2).
Route::get('products/{product}/images', [ProductImageController::class, 'index']);
Route::post('products/{product}/images', [ProductImageController::class, 'store']);
Route::patch('products/{product}/images/reorder', [ProductImageController::class, 'reorder']);
Route::patch('products/{product}/images/{image}', [ProductImageController::class, 'update'])
    ->where('image', '[0-9]+');
Route::delete('products/{product}/images/{image}', [ProductImageController::class, 'destroy'])
    ->where('image', '[0-9]+');

require __DIR__.'/routes_catalog.php';
