<?php

use App\Modules\Products\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('products/{product}/price', [ProductController::class, 'price']);
Route::apiResource('products', ProductController::class);
