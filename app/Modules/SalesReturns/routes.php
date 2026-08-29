<?php

use App\Modules\SalesReturns\Controllers\SalesReturnController;
use Illuminate\Support\Facades\Route;

Route::post('sales-returns/{salesReturn}/approve', [SalesReturnController::class, 'approve'])->middleware('idempotency');
Route::post('sales-returns/{salesReturn}/reject', [SalesReturnController::class, 'reject'])->middleware('idempotency');
Route::post('sales-returns/{salesReturn}/process', [SalesReturnController::class, 'process'])->middleware('idempotency');
Route::post('sales-returns/{salesReturn}/exchange', [SalesReturnController::class, 'exchange'])->middleware('idempotency');
Route::post('sales-returns/{salesReturn}/exchange/complete', [SalesReturnController::class, 'completeExchange'])->middleware('idempotency');
Route::post('sales-returns/{salesReturn}/cancel', [SalesReturnController::class, 'cancel'])->middleware('idempotency');

Route::apiResource('sales-returns', SalesReturnController::class)
    ->only(['index', 'show']);

Route::post('sales-returns', [SalesReturnController::class, 'store'])
    ->middleware('idempotency');
