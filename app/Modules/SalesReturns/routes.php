<?php

use App\Modules\SalesReturns\Controllers\SalesReturnController;
use Illuminate\Support\Facades\Route;

Route::post('sales-returns/{salesReturn}/approve', [SalesReturnController::class, 'approve']);
Route::post('sales-returns/{salesReturn}/reject', [SalesReturnController::class, 'reject']);
Route::post('sales-returns/{salesReturn}/process', [SalesReturnController::class, 'process']);
Route::post('sales-returns/{salesReturn}/cancel', [SalesReturnController::class, 'cancel']);

Route::apiResource('sales-returns', SalesReturnController::class)
    ->only(['index', 'store', 'show']);
