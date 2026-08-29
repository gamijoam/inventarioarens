<?php

use App\Modules\ProductExits\Controllers\ProductExitController;
use Illuminate\Support\Facades\Route;

Route::apiResource('product-exits', ProductExitController::class)
    ->parameters(['product-exits' => 'productExit'])
    ->only(['index', 'show']);

Route::post('product-exits', [ProductExitController::class, 'store'])
    ->middleware('idempotency');
